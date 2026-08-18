<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\InstallmentStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\CostSetting;
use App\Models\Installment;
use App\Models\Transaction;
use App\Services\Pricing\CompanyHourCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * O painel financeiro do inquilino: parcelamento e métricas do caixa.
 *
 * Diferente do PricingEngine, este serviço NÃO é puro e não tem gêmeo em
 * TypeScript — de propósito. Ele agrega o banco inteiro do mês, e não há como
 * (nem por que) recalcular isso no navegador a cada tecla: o painel abre uma
 * vez, com números que só mudam quando alguém lança algo.
 */
class FinancialEngine
{
    public function __construct(
        private readonly CompanyHourCalculator $companyHour,
    ) {}

    /**
     * Divide a transação em parcelas com vencimentos mensais consecutivos.
     *
     * O DETALHE QUE IMPORTA é o centavo. R$ 100 em 3× dá 33,3333..., e três
     * parcelas de R$ 33,33 somam R$ 99,99 — um centavo evapora em toda venda
     * parcelada, e o caixa nunca fecha com o extrato. A sobra vai para a
     * ÚLTIMA parcela (33,33 + 33,33 + 33,34), que é a praxe brasileira: o
     * cliente vê o valor cheio nas primeiras e a diferença aparece no fim, onde
     * ninguém a confunde com erro de cálculo.
     *
     * @param  int  $count  Número de parcelas (1 = à vista).
     * @param  ?Carbon  $firstDueDate  Vencimento da primeira; padrão é a data da transação.
     * @return list<Installment>
     *
     * @throws \DomainException
     */
    public function generateInstallments(
        Transaction $transaction,
        int $count,
        ?Carbon $firstDueDate = null,
    ): array {
        if ($count < 1) {
            throw new \DomainException('O número de parcelas precisa ser ao menos 1.');
        }

        if ($transaction->amount <= 0) {
            throw new \DomainException('Não é possível parcelar uma transação sem valor.');
        }

        // Centavos: a divisão acontece em INTEIROS, e não em float. 0,1 + 0,2
        // não dá 0,3 em ponto flutuante, e um caixa que erra na terceira casa
        // acumula diferença a cada fechamento.
        $totalCentavos = (int) round($transaction->amount * 100);
        $base = intdiv($totalCentavos, $count);
        $sobra = $totalCentavos - ($base * $count);

        $primeiro = $firstDueDate ?? Carbon::instance($transaction->transaction_date);

        $parcelas = [];

        for ($numero = 1; $numero <= $count; $numero++) {
            $centavos = $base + ($numero === $count ? $sobra : 0);

            $parcelas[] = $transaction->installments()->create([
                'tenant_id' => $transaction->tenant_id,
                'installment_number' => $numero,
                'total_installments' => $count,
                'amount' => $centavos / 100,

                /*
                 * addMonthsNoOverflow: uma venda em 31/01 parcelada em 3×
                 * venceria em 31/02, que não existe. O `NoOverflow` puxa para
                 * 28/02 em vez de transbordar para 03/03 — o comportamento que
                 * todo carnê brasileiro tem.
                 */
                'due_date' => $primeiro->copy()->addMonthsNoOverflow($numero - 1),
                'status' => InstallmentStatus::Pending,
            ]);
        }

        return $parcelas;
    }

    /**
     * Redistribui o valor entre as parcelas que já existem.
     *
     * Chamado quando um lançamento é corrigido. Sem isto o lançamento diria
     * R$ 500 e as parcelas somariam R$ 800 — e o painel financeiro, que lê as
     * PARCELAS, mostraria um número que a lista de lançamentos contradiz. Duas
     * telas do mesmo dinheiro discordando é o tipo de erro que ninguém
     * investiga porque cada uma parece certa sozinha.
     *
     * Só o valor muda. A quantidade de parcelas e os vencimentos foram
     * combinados com o cliente, e recriá-los transformaria uma correção de
     * digitação numa renegociação que ninguém pediu.
     *
     * Mesma aritmética em CENTAVOS de `generateInstallments`, e pela mesma
     * razão: a sobra da divisão vai para a última parcela, para que a soma feche
     * exatamente com o total.
     */
    public function redistribute(Transaction $transaction): void
    {
        $parcelas = $transaction->installments()->orderBy('installment_number')->get();

        if ($parcelas->isEmpty()) {
            return;
        }

        $total = $parcelas->count();
        $totalCentavos = (int) round($transaction->amount * 100);
        $base = intdiv($totalCentavos, $total);
        $sobra = $totalCentavos - ($base * $total);

        foreach ($parcelas as $indice => $parcela) {
            $ultima = $indice === $total - 1;

            $parcela->update(['amount' => ($base + ($ultima ? $sobra : 0)) / 100]);
        }
    }

    /**
     * As métricas do painel para um mês.
     *
     * @return array<string, mixed>
     */
    public function dashboardMetrics(int $month, int $year): array
    {
        $realizado = $this->somaRealizada($month, $year, TransactionType::Entry);
        $projetado = $this->somaProjetada($month, $year, TransactionType::Entry);

        $despesaRealizada = $this->somaRealizada($month, $year, TransactionType::Exit);
        $despesaProjetada = $this->somaProjetada($month, $year, TransactionType::Exit);

        $equilibrio = $this->breakEven($month, $year);

        return [
            'period' => ['month' => $month, 'year' => $year],

            'revenue' => [
                'realized' => $realizado,
                'projected' => $projetado,
            ],

            'expenses' => [
                'realized' => $despesaRealizada,
                'projected' => $despesaProjetada,
            ],

            // Saldo do que efetivamente passou pelo caixa. Pode ser negativo, e
            // exibir o negativo é o ponto: é o mês em que saiu mais do que entrou.
            'net_realized' => round($realizado - $despesaRealizada, 2),

            'break_even' => $equilibrio,

            'revenue_distribution' => $this->revenueDistribution($month, $year, $realizado),

            'overdue' => $this->overdue(),
        ];
    }

    /** Parcelas QUITADAS no mês — dinheiro que passou pelo caixa. */
    private function somaRealizada(int $month, int $year, TransactionType $type): float
    {
        return round((float) Installment::query()
            ->settledIn($month, $year)
            ->whereHas('transaction', fn ($q) => $q->where('type', $type))
            ->sum('amount'), 2);
    }

    /** Parcelas que VENCEM no mês, quitadas ou não — a promessa. */
    private function somaProjetada(int $month, int $year, TransactionType $type): float
    {
        return round((float) Installment::query()
            ->dueIn($month, $year)
            ->whereHas('transaction', fn ($q) => $q->where('type', $type))
            ->sum('amount'), 2);
    }

    /**
     * Ponto de equilíbrio: o faturamento mínimo para cobrir o custo fixo.
     *
     * DUAS CORREÇÕES em relação ao desenho original da Fase 4:
     *
     * 1. O custo fixo NÃO sai de `cost_settings`. Desde a Fase 2, aquela tabela
     *    guarda TAXAS (R$/hora); a despesa mensal mora em `fixed_costs`. Reusar
     *    o `costBase()` da hora-empresa mantém uma única definição de "quanto
     *    esta empresa gasta por mês" — duas somas divergiriam no primeiro
     *    aluguel reajustado.
     *
     * 2. A margem é devolvida em PERCENTUAL e a divisão usa `margem / 100`. O
     *    documento definia a margem como fração (0,40) e ainda dividia por
     *    `margem/100`, o que dá 250× o custo fixo em vez de 2,5× — um ponto de
     *    equilíbrio cem vezes maior que o real, que faria qualquer negócio
     *    saudável parecer inviável.
     *
     * @return array<string, float|string|null>
     */
    private function breakEven(int $month, int $year): array
    {
        $settings = rescue(fn () => CostSetting::current(), null, report: false);

        $custoFixo = $this->companyHour->costBase(
            $settings?->company_includes_depreciation ?? true,
        );

        /*
         * A margem sai SÓ das vendas cujo custo variável o sistema conhece —
         * hoje, orçamentos aprovados. Incluir revenda de produto com custo
         * desconhecido a trataria como custo zero, inflando a margem e
         * rebaixando o ponto de equilíbrio. É o erro perigoso: faz o negócio
         * parecer mais saudável do que é.
         */
        $vendas = Transaction::query()
            ->entries()
            ->where('category', TransactionCategory::QuoteSale)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with('quote')
            ->get();

        $receita = round((float) $vendas->sum('amount'), 2);

        $custoVariavel = round(
            (float) $vendas->sum(fn (Transaction $t): float => $t->variableCost() ?? 0.0),
            2,
        );

        if ($receita <= 0) {
            /*
             * Sem venda no mês não há margem para apurar. Devolver o custo fixo
             * como meta é a resposta honesta — é literalmente quanto precisa
             * entrar para o mês não dar prejuízo — e evita a divisão por zero
             * que o próprio documento manda tratar.
             */
            return [
                'fixed_cost' => $custoFixo,
                'contribution_margin_percent' => null,
                'target_revenue' => $custoFixo,
                'basis' => 'sem-vendas',
            ];
        }

        $margemPercent = round(($receita - $custoVariavel) / $receita * 100, 2);

        if ($margemPercent <= 0) {
            /*
             * Margem zero ou negativa: cada venda custa mais insumo do que
             * arrecada. Não existe faturamento que cubra o fixo nessa condição —
             * vender mais aumenta o prejuízo. A meta vira o próprio custo fixo,
             * com o motivo declarado, para o painel poder alertar em vez de
             * exibir um número astronômico sem explicação.
             */
            return [
                'fixed_cost' => $custoFixo,
                'contribution_margin_percent' => $margemPercent,
                'target_revenue' => $custoFixo,
                'basis' => 'margem-nao-positiva',
            ];
        }

        return [
            'fixed_cost' => $custoFixo,
            'contribution_margin_percent' => $margemPercent,
            'target_revenue' => round($custoFixo / ($margemPercent / 100), 2),
            'basis' => 'margem-apurada',
        ];
    }

    /**
     * Distribuição do faturamento realizado por categoria.
     *
     * @return list<array<string, mixed>>
     */
    private function revenueDistribution(int $month, int $year, float $totalRealizado): array
    {
        $porCategoria = Installment::query()
            ->settledIn($month, $year)
            ->join('transactions', 'installments.transaction_id', '=', 'transactions.id')
            ->where('transactions.type', TransactionType::Entry->value)
            ->groupBy('transactions.category')
            ->select('transactions.category', DB::raw('SUM(installments.amount) as total'))
            ->pluck('total', 'transactions.category');

        $linhas = [];

        foreach ($porCategoria as $categoria => $total) {
            $caso = TransactionCategory::tryFrom((string) $categoria) ?? TransactionCategory::Other;
            $valor = round((float) $total, 2);

            $linhas[] = [
                'category' => $caso->value,
                'label' => $caso->label(),
                'amount' => $valor,
                // Percentual sobre o realizado do mês. Zero total devolve 0 em
                // vez de estourar — mês sem venda é normal, não é erro.
                'percent' => $totalRealizado > 0
                    ? round($valor / $totalRealizado * 100, 2)
                    : 0.0,
            ];
        }

        usort($linhas, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return $linhas;
    }

    /**
     * O que já venceu e continua em aberto.
     *
     * Não filtra por mês de propósito: uma parcela de março que ninguém pagou
     * continua sendo problema em agosto, e some do painel se o atraso for
     * consultado dentro do recorte do mês corrente.
     *
     * @return array<string, float|int>
     */
    private function overdue(): array
    {
        $vencidas = Installment::query()
            ->pending()
            ->whereDate('due_date', '<', now()->startOfDay())
            ->whereHas('transaction', fn ($q) => $q->where('type', TransactionType::Entry))
            ->get();

        return [
            'count' => $vencidas->count(),
            'amount' => round((float) $vencidas->sum('amount'), 2),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Enums\SubscriptionPaymentStatus;
use App\Models\Client;
use App\Models\Material;
use App\Models\Quote;
use App\Models\Scopes\TenantScope;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Os números do negócio — a visão de quem opera o SaaS, não de quem o assina.
 *
 * TODA contagem aqui é explicitamente `withoutGlobalScope(TenantScope::class)`,
 * e isso não é redundância. O TenantScope hoje não filtra nada para o admin de
 * plataforma, porque o `tenant_id` dele é nulo — então as contagens já sairiam
 * certas sem a chamada. O problema é que estariam certas por ACIDENTE: no dia em
 * que o escopo passar a resolver a empresa de outro jeito (impersonação para
 * suporte, por exemplo), o painel começaria a mostrar os números de uma empresa
 * só, sem erro, sem exceção e sem nada na tela denunciando. Dizer explicitamente
 * "sem escopo" transforma uma coincidência em contrato.
 */
class PlatformMetrics
{
    /** @return array<string, mixed> */
    public function all(?Carbon $referencia = null): array
    {
        $mes = ($referencia ?? now())->copy()->startOfMonth();

        return [
            'periodo' => [
                'inicio' => $mes->toDateString(),
                'fim' => $mes->copy()->endOfMonth()->toDateString(),
            ],
            'assinantes' => $this->assinantes(),
            'faturamento' => $this->faturamento($mes),
            'demografia' => $this->demografia(),
            'consumo' => $this->consumo(),
        ];
    }

    /**
     * Empresas por plano e por situação.
     *
     * @return array<string, mixed>
     */
    public function assinantes(): array
    {
        $porPlano = [];

        foreach (PlanType::cases() as $plano) {
            $porPlano[$plano->value] = [
                'rotulo' => $plano->label(),
                'mensalidade' => $plano->monthlyPrice(),
                'total' => Tenant::query()->where('plan_type', $plano)->count(),
                'ativos' => Tenant::query()
                    ->where('plan_type', $plano)
                    ->where('is_active', true)
                    ->whereIn('plan_status', [PlanStatus::Active, PlanStatus::Trialing])
                    ->count(),
            ];
        }

        $porSituacao = [];

        foreach (PlanStatus::cases() as $situacao) {
            $porSituacao[$situacao->value] = [
                'rotulo' => $situacao->label(),
                'total' => Tenant::query()->where('plan_status', $situacao)->count(),
            ];
        }

        return [
            'total' => Tenant::query()->count(),
            'ativos' => Tenant::query()->where('is_active', true)->count(),
            'por_plano' => $porPlano,
            'por_situacao' => $porSituacao,
        ];
    }

    /**
     * Receita.
     *
     * Os três números leem como um extrato bancário, e a ordem importa:
     *
     *  • `bruto_mes` = dinheiro que ENTROU no mês. Inclui o que foi estornado
     *    depois, porque estornado depois não deixa de ter entrado. Filtrar por
     *    `status = Paid` seria o erro fácil aqui: o estorno muda o status da
     *    linha, some da entrada E aparece na saída, e o líquido subtrairia o
     *    mesmo valor duas vezes — R$ 79,90 de venda com R$ 149,90 estornados
     *    viraria um prejuízo de R$ 70,00 que não existiu.
     *  • `estornado_mes` = devoluções ocorridas no mês, pela data do estorno.
     *    Um arrependimento em fevereiro sobre uma venda de janeiro sai do
     *    fevereiro, exatamente como no extrato.
     *  • `liquido_mes` = a diferença.
     *
     * `mrr` é outra coisa e por isso tem nome próprio: a receita recorrente
     * contratada, projeção do que entra se ninguém cancelar. Misturar os dois
     * num só número é como tratar relatório de vendas como extrato — o primeiro
     * promete, o segundo aconteceu.
     *
     * @return array<string, mixed>
     */
    public function faturamento(Carbon $inicioDoMes): array
    {
        $fimDoMes = $inicioDoMes->copy()->endOfMonth();

        $bruto = (float) SubscriptionPayment::query()
            ->whereIn('status', [
                SubscriptionPaymentStatus::Paid,
                SubscriptionPaymentStatus::Refunded,
            ])
            ->whereBetween('paid_at', [$inicioDoMes, $fimDoMes])
            ->sum('amount');

        $estornado = (float) SubscriptionPayment::query()
            ->where('status', SubscriptionPaymentStatus::Refunded)
            ->whereBetween('refunded_at', [$inicioDoMes, $fimDoMes])
            ->sum('refunded_amount');

        $mrr = 0.0;

        foreach (PlanType::cases() as $plano) {
            if (! $plano->isPaid()) {
                continue;
            }

            $ativos = Tenant::query()
                ->where('plan_type', $plano)
                ->where('is_active', true)
                ->where('plan_status', PlanStatus::Active)
                ->count();

            $mrr += $ativos * $plano->monthlyPrice();
        }

        return [
            'bruto_mes' => round($bruto, 2),
            'estornado_mes' => round($estornado, 2),
            'liquido_mes' => round($bruto - $estornado, 2),
            'mrr' => round($mrr, 2),
        ];
    }

    /**
     * Penetração geográfica: empresas ativas por UF.
     *
     * Ordenado por volume porque a pergunta que o painel responde é "onde estou
     * ganhando", não "o que vem antes no alfabeto". Empresas sem UF preenchida
     * viram a chave `nao_informado` em vez de sumirem — um estado vazio grande
     * demais é sinal de cadastro incompleto, e esconder isso faria o mapa
     * parecer mais preciso do que é.
     *
     * @return array<int, array{uf: string, total: int}>
     */
    public function demografia(): array
    {
        return Tenant::query()
            ->where('is_active', true)
            ->selectRaw('state, COUNT(*) as total')
            ->groupBy('state')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($linha) => [
                'uf' => $linha->state ?: 'nao_informado',
                'total' => (int) $linha->total,
            ])
            ->values()
            ->all();
    }

    /**
     * Volume de registros gravados na plataforma inteira — o custo de banco.
     *
     * @return array<string, int>
     */
    public function consumo(): array
    {
        return [
            'materiais' => $this->contarTudo(Material::query()),
            'orcamentos' => $this->contarTudo(Quote::query()->withTrashed()),
            'clientes' => $this->contarTudo(Client::query()),
            'usuarios' => User::query()->whereNotNull('tenant_id')->count(),
        ];
    }

    /** @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query */
    private function contarTudo(Builder $query): int
    {
        return $query->withoutGlobalScope(TenantScope::class)->count();
    }
}

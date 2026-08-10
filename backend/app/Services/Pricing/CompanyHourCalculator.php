<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\EfficiencyScenario;
use App\Models\CostSetting;
use App\Models\Equipment;
use App\Models\FixedCost;

/**
 * Custo da hora e do minuto da empresa.
 *
 * A conta responde à pergunta que todo cartonageiro erra: "quanto custa eu
 * ficar uma hora aqui dentro?". A despesa mensal corre independentemente de
 * produzir — aluguel, contador, energia e pró-labore chegam iguais no mês
 * parado. Dividi-la pelas horas que realmente produzem transforma custo fixo em
 * custo por minuto, que é a unidade em que o orçamento sabe pensar.
 *
 * O divisor é o que engana. Dividir pelas horas PAGAS (8×22 = 176) dá um número
 * confortável e errado, porque parte dessas horas não produziu nada — e a
 * despesa delas precisa ser absorvida pelas horas que produziram. É por isso
 * que o fator de eficiência aparece aqui e não no fim, como desconto.
 */
class CompanyHourCalculator
{
    public function __construct(
        private readonly DepreciationCalculator $depreciation,
    ) {}

    /**
     * Soma das despesas fixas mensais ATIVAS da empresa.
     *
     * Escopada pelo TenantScope; `active()` respeita a linha que o usuário
     * desligou para simular um corte.
     */
    public function fixedCostsTotal(): float
    {
        return round((float) FixedCost::query()->active()->sum('monthly_amount'), 2);
    }

    /**
     * Base de custo do mês: despesa fixa, com ou sem a depreciação do parque.
     *
     * A opção existe porque as duas escolas são defensáveis e o sistema não
     * deve decidir pelo usuário. Incluir a depreciação é o correto para quem
     * quer repor a máquina no fim da vida útil; excluir é o que faz quem já
     * paga a máquina em parcelas lançadas como custo fixo — nesse caso somar a
     * depreciação cobraria a mesma máquina duas vezes.
     */
    public function costBase(bool $includeDepreciation): float
    {
        $base = $this->fixedCostsTotal();

        if ($includeDepreciation) {
            $base += $this->depreciation->monthlyTotal();
        }

        return round($base, 2);
    }

    /**
     * O custo do minuto que o motor de preço deve usar, ou null.
     *
     * É o ponto único onde a configuração vira número para o PricingEngine.
     * Existe para que simulação, gravação e o payload do preview no navegador
     * partam da MESMA conta — três lugares repetindo a chamada acabariam
     * divergindo, e a divergência apareceria como um preço na tela diferente do
     * preço gravado.
     *
     * Null com o modo desligado, e o motor volta ao regime de estimativa.
     */
    public function minuteCostFor(CostSetting $settings): ?float
    {
        if (! $settings->use_company_hour) {
            return null;
        }

        $cenario = EfficiencyScenario::tryFrom($settings->company_efficiency_percent)
            ?? EfficiencyScenario::Recommended;

        $resultado = $this->calculate(
            hoursPerDay: $settings->company_hours_per_day,
            daysPerMonth: $settings->company_days_per_month,
            active: $cenario,
            includeDepreciation: $settings->company_includes_depreciation,
            monthlyProduction: (float) $settings->monthly_production_volume,
        );

        return $resultado['active_scenario']['minute_cost'];
    }

    /**
     * O cálculo completo, com o cenário ativo e a matriz de comparação.
     *
     * @param  float  $hoursPerDay  Horas trabalhadas por dia.
     * @param  float  $daysPerMonth  Dias trabalhados no mês.
     * @param  EfficiencyScenario  $active  Cenário escolhido pelo usuário.
     * @param  bool  $includeDepreciation  Somar a depreciação das máquinas à base.
     * @return array<string, mixed>
     *
     * @throws \DomainException quando a jornada informada não produz hora alguma.
     */
    public function calculate(
        float $hoursPerDay,
        float $daysPerMonth,
        EfficiencyScenario $active,
        bool $includeDepreciation,
        float $monthlyProduction = 0.0,
    ): array {
        /*
         * Lê o banco e delega a MATEMÁTICA para compute().
         *
         * A separação existe por causa da paridade: compute() é puro — recebe
         * listas de números e devolve o resultado — e por isso pode ter gêmeo
         * em TypeScript. Uma função que consulta Eloquent não teria como ser
         * espelhada, e a tela de configuração de custos precisa recalcular a
         * hora a cada tecla sem ida ao servidor.
         */
        return $this->compute(
            fixedCostAmounts: FixedCost::query()->active()->pluck('monthly_amount')->all(),
            equipment: Equipment::query()
                ->get(['purchase_value', 'useful_life_months'])
                ->map(fn (Equipment $e): array => [
                    'purchase_value' => $e->purchase_value,
                    'useful_life_months' => $e->useful_life_months,
                ])
                ->all(),
            hoursPerDay: $hoursPerDay,
            daysPerMonth: $daysPerMonth,
            active: $active,
            includeDepreciation: $includeDepreciation,
            monthlyProduction: $monthlyProduction,
        );
    }

    /**
     * O cálculo puro — sem banco, sem request, espelhável em TypeScript.
     *
     * ⚠️  Espelha calculateCompanyHour() em frontend/lib/pricing/engine.ts.
     * Alterar a ordem dos arredondamentos aqui sem alterar lá quebra a
     * paridade, e a tela passaria a mostrar uma hora e o motor a cobrar outra.
     *
     * @param  list<float>  $fixedCostAmounts  Despesas mensais ATIVAS.
     * @param  list<array{purchase_value: float, useful_life_months: int}>  $equipment
     * @return array<string, mixed>
     *
     * @throws \DomainException quando a jornada informada não produz hora alguma.
     */
    public function compute(
        array $fixedCostAmounts,
        array $equipment,
        float $hoursPerDay,
        float $daysPerMonth,
        EfficiencyScenario $active,
        bool $includeDepreciation,
        float $monthlyProduction = 0.0,
    ): array {
        $fixedCostsTotal = round(array_sum($fixedCostAmounts), 2);

        /*
         * Cada máquina é arredondada ANTES de entrar no total.
         *
         * É o mesmo que Equipment::monthly_depreciation faz para a tela, e é o
         * que garante que o total bata com a soma das linhas que o usuário vê.
         * Somar os valores cheios daria um total que não fecha na conferência.
         */
        $depreciationTotal = 0.0;

        foreach ($equipment as $maquina) {
            $depreciationTotal += $maquina['useful_life_months'] > 0
                ? round($maquina['purchase_value'] / $maquina['useful_life_months'], 2)
                : 0.0;
        }

        $depreciationTotal = round($depreciationTotal, 2);

        $costBase = round(
            $fixedCostsTotal + ($includeDepreciation ? $depreciationTotal : 0.0),
            2,
        );

        $monthlyHours = $this->monthlyHours($hoursPerDay, $daysPerMonth);

        return [
            'parameters' => [
                'hours_per_day' => $hoursPerDay,
                'days_per_month' => $daysPerMonth,
                'efficiency_percent' => $active->value,
                'include_depreciation' => $includeDepreciation,
                'monthly_production_volume' => $monthlyProduction,
            ],

            /*
             * A base aberta em duas parcelas, e não só o total.
             *
             * É o que permite ao usuário conferir o efeito do botão de
             * depreciação sem refazer a conta: ele vê a parcela que entrou.
             */
            'cost_base' => [
                'fixed_costs' => $fixedCostsTotal,
                'depreciation' => $includeDepreciation ? $depreciationTotal : 0.0,
                'total' => $costBase,
            ],

            'monthly_hours' => round($monthlyHours, 2),

            /*
             * Impacto da depreciação em CADA peça.
             *
             * Usa o total cheio do parque, e não a parcela que entrou na base:
             * a pergunta "quanto de máquina tem nesta caixa" não muda porque o
             * usuário desligou o botão — desligar muda como ele COBRA, não o
             * que ele consome.
             *
             * Produção não declarada devolve 0.0 em vez de estourar: uma
             * configuração incompleta não pode derrubar toda simulação de preço
             * do sistema. Quem PERGUNTA o impacto explicitamente recebe exceção
             * — ver DepreciationCalculator::perUnit().
             */
            'depreciation_per_unit' => $monthlyProduction > 0
                ? round($depreciationTotal / $monthlyProduction, 4)
                : 0.0,

            'active_scenario' => $this->scenario($active, $costBase, $monthlyHours),

            'comparison' => array_map(
                fn (EfficiencyScenario $cenario): array => $this->scenario($cenario, $costBase, $monthlyHours),
                EfficiencyScenario::comparison(),
            ),
        ];
    }

    /**
     * Horas pagas no mês. É o teto, não o que produz.
     *
     * @throws \DomainException
     */
    private function monthlyHours(float $hoursPerDay, float $daysPerMonth): float
    {
        $horas = $hoursPerDay * $daysPerMonth;

        if ($horas <= 0) {
            /*
             * Sem hora trabalhada não existe hora-empresa: a despesa fixa
             * continua correndo e não há hora nenhuma para absorvê-la. Devolver
             * zero afirmaria que trabalhar de graça sai de graça — a mesma
             * mentira que produção zero seria no rateio da depreciação.
             */
            throw new \DomainException(
                'Informe as horas por dia e os dias por mês: sem jornada não há hora produtiva para ratear os custos.'
            );
        }

        return $horas;
    }

    /**
     * Um cenário resolvido: horas produtivas, custo da hora e do minuto.
     *
     * @return array<string, mixed>
     */
    private function scenario(EfficiencyScenario $cenario, float $costBase, float $monthlyHours): array
    {
        $productiveHours = $monthlyHours * $cenario->factor();

        // 2 casas na hora: é o número que a tela exibe e o usuário confere.
        $hourCost = round($costBase / $productiveHours, 2);

        /*
         * O minuto deriva da hora JÁ ARREDONDADA, e não da divisão cheia.
         *
         * Mesma regra da depreciação anual: quem multiplicar o custo do minuto
         * por 60 na calculadora precisa chegar exatamente no custo da hora que
         * está na tela. Um centavo de diferença entre dois números exibidos
         * lado a lado destrói a confiança na conta inteira.
         *
         * 4 casas porque o minuto é valor unitário: uma peça leva 2,5 minutos e
         * pode ser multiplicada por 50.000 — arredondar para centavos aqui
         * distorceria o total. Ver PricingEngine::money().
         */
        $minuteCost = round($hourCost / 60, 4);

        return [
            'efficiency_percent' => $cenario->value,
            'label' => $cenario->label(),
            'description' => $cenario->description(),
            'productive_hours' => round($productiveHours, 2),
            'hour_cost' => $hourCost,
            'minute_cost' => $minuteCost,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EfficiencyScenario;
use App\Services\Pricing\CompanyHourCalculator;
use App\Services\Pricing\PricingEngine;
use Illuminate\Console\Command;

/**
 * Exporta casos de paridade do cálculo da hora-empresa (Fase 2).
 *
 * Irmão de pricing:export-fixtures, e existe pelo mesmo motivo: a hora-empresa
 * também tem motor duplicado. O PHP é a autoridade que alimenta o preço; o TS
 * recalcula na tela de configuração a cada tecla, para que mexer no fator de
 * eficiência responda na hora em vez de esperar a rede.
 *
 * A duplicação aqui é ainda mais perigosa que a do preço: um erro na hora-
 * empresa não aparece como número estranho na tela — aparece meses depois,
 * como margem que nunca fechou.
 *
 * Uso: php artisan pricing:export-hour-fixtures
 */
class ExportCompanyHourFixtures extends Command
{
    protected $signature = 'pricing:export-hour-fixtures
                            {--path= : Caminho do JSON de saída}';

    protected $description = 'Gera os casos de paridade da hora-empresa entre PHP e TypeScript';

    public function handle(CompanyHourCalculator $calculator): int
    {
        $path = $this->option('path')
            ?: base_path('../frontend/lib/pricing/__fixtures__/company-hour.json');

        $cases = [];

        foreach ($this->scenarios() as $name => $scenario) {
            $cases[] = [
                'name' => $name,
                'fixed_cost_amounts' => $scenario['fixedCostAmounts'],
                'equipment' => $scenario['equipment'],
                'params' => [
                    'hours_per_day' => $scenario['hoursPerDay'],
                    'days_per_month' => $scenario['daysPerMonth'],
                    'efficiency_percent' => $scenario['active']->value,
                    'include_depreciation' => $scenario['includeDepreciation'],
                    'monthly_production_volume' => $scenario['monthlyProduction'],
                ],
                'expected' => $calculator->compute(
                    fixedCostAmounts: $scenario['fixedCostAmounts'],
                    equipment: $scenario['equipment'],
                    hoursPerDay: $scenario['hoursPerDay'],
                    daysPerMonth: $scenario['daysPerMonth'],
                    active: $scenario['active'],
                    includeDepreciation: $scenario['includeDepreciation'],
                    monthlyProduction: $scenario['monthlyProduction'],
                ),
            ];
        }

        @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, json_encode([
            'engine_version' => PricingEngine::VERSION,
            'generated_at' => now()->toIso8601String(),
            'cases' => $cases,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info(count($cases).' casos exportados para '.realpath($path));

        return self::SUCCESS;
    }

    /**
     * Matriz de cenários.
     *
     * Cada eixo isolado e depois combinado: a jornada, o fator, o botão de
     * depreciação, o volume de produção, e as bordas onde a aritmética quebra
     * (parque vazio, vida útil zero, jornada fracionada).
     *
     * @return array<string, array<string, mixed>>
     */
    private function scenarios(): array
    {
        // R$ 8.800/mês em 176 horas pagas dá exatamente R$ 50/h a 100%: números
        // redondos para que um teste que falhe acuse a fórmula, não um centavo
        // de arredondamento vindo da fixture.
        $base = fn (array $o = []) => [
            'fixedCostAmounts' => $o['fixedCostAmounts'] ?? [8800.00],
            'equipment' => $o['equipment'] ?? [],
            'hoursPerDay' => $o['hoursPerDay'] ?? 8.0,
            'daysPerMonth' => $o['daysPerMonth'] ?? 22.0,
            'active' => $o['active'] ?? EfficiencyScenario::Recommended,
            'includeDepreciation' => $o['includeDepreciation'] ?? false,
            'monthlyProduction' => $o['monthlyProduction'] ?? 75.0,
        ];

        $vincadeira = ['purchase_value' => 12000.00, 'useful_life_months' => 60];
        $guilhotina = ['purchase_value' => 39000.00, 'useful_life_months' => 60];

        return [
            'jornada-padrao' => $base(),
            'cenario-otimista' => $base(['active' => EfficiencyScenario::Optimistic]),
            'cenario-conservador' => $base(['active' => EfficiencyScenario::Conservative]),

            // Sem despesa nenhuma: a hora custa zero e nada pode estourar.
            'sem-despesa' => $base(['fixedCostAmounts' => []]),

            // Várias linhas: prova que a soma percorre a lista inteira e
            // arredonda o TOTAL, não cada parcela.
            'varias-despesas' => $base([
                'fixedCostAmounts' => [3500.00, 1200.50, 890.33, 2100.17, 1109.00],
            ]),

            'com-depreciacao' => $base([
                'equipment' => [$vincadeira, $guilhotina],
                'includeDepreciation' => true,
            ]),
            'depreciacao-desligada' => $base([
                'equipment' => [$vincadeira, $guilhotina],
                'includeDepreciation' => false,
            ]),

            /*
             * Vida útil que não divide redondo: 10.000/60 = 166,666...
             * Cada máquina é arredondada ANTES de somar, e este caso é o que
             * denuncia quem somar os valores cheios.
             */
            'depreciacao-com-dizima' => $base([
                'equipment' => [
                    ['purchase_value' => 10000.00, 'useful_life_months' => 60],
                    ['purchase_value' => 10000.00, 'useful_life_months' => 60],
                    ['purchase_value' => 10000.00, 'useful_life_months' => 60],
                ],
                'includeDepreciation' => true,
            ]),

            // Vida útil zero: guarda de integridade, não pode estourar divisão.
            'maquina-com-vida-util-zero' => $base([
                'equipment' => [['purchase_value' => 5000.00, 'useful_life_months' => 0]],
                'includeDepreciation' => true,
            ]),

            'jornada-meio-periodo' => $base(['hoursPerDay' => 4.0]),
            'jornada-fracionada' => $base(['hoursPerDay' => 7.5, 'daysPerMonth' => 21.5]),
            'jornada-intensa' => $base(['hoursPerDay' => 12.0, 'daysPerMonth' => 26.0]),

            'producao-alta' => $base([
                'equipment' => [$vincadeira],
                'includeDepreciation' => true,
                'monthlyProduction' => 5000.0,
            ]),
            'producao-baixa' => $base([
                'equipment' => [$vincadeira],
                'includeDepreciation' => true,
                'monthlyProduction' => 12.0,
            ]),
            // Volume não declarado: o rateio por peça vira 0 em vez de estourar.
            'producao-nao-declarada' => $base([
                'equipment' => [$vincadeira],
                'includeDepreciation' => true,
                'monthlyProduction' => 0.0,
            ]),

            'tudo-combinado' => $base([
                'fixedCostAmounts' => [3500.00, 1200.50, 890.33],
                'equipment' => [$vincadeira, $guilhotina],
                'hoursPerDay' => 7.5,
                'daysPerMonth' => 21.5,
                'active' => EfficiencyScenario::Conservative,
                'includeDepreciation' => true,
                'monthlyProduction' => 340.0,
            ]),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BoxModel;
use App\Enums\MaterialUnit;
use App\Models\CostSetting;
use App\Models\Material;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\PricingInput;
use Illuminate\Console\Command;

/**
 * Exporta casos de teste do motor PHP para um JSON consumido pelo motor TS.
 *
 * Este comando é a metade backend do teste de paridade. O motor duplicado
 * (PHP para gravar, TS para o preview em tempo real) é uma decisão consciente
 * de performance, mas cria o risco de os dois divergirem em silêncio depois de
 * uma alteração feita em apenas um dos lados.
 *
 * O fixture gerado aqui é a fonte de verdade: `npm run test:parity` roda o
 * motor TS contra estes casos e falha se qualquer campo divergir.
 *
 * Uso: php artisan pricing:export-fixtures
 */
class ExportPricingFixtures extends Command
{
    protected $signature = 'pricing:export-fixtures
                            {--path= : Caminho do JSON de saída}';

    protected $description = 'Gera os casos de paridade entre os motores PHP e TypeScript';

    public function handle(PricingEngine $engine): int
    {
        $path = $this->option('path')
            ?: base_path('../frontend/lib/pricing/__fixtures__/parity.json');

        $cases = [];

        foreach ($this->scenarios() as $name => $scenario) {
            $input = new PricingInput(
                material: $scenario['material'],
                settings: $scenario['settings'],
                boxModel: $scenario['boxModel'],
                widthMm: $scenario['widthMm'],
                heightMm: $scenario['heightMm'],
                depthMm: $scenario['depthMm'],
                quantity: $scenario['quantity'],
                wastePercent: $scenario['wastePercent'],
                productionMinutesPerUnit: $scenario['productionMinutes'],
                profitMarginPercent: $scenario['marginPercent'],
                pricingMode: $scenario['pricingMode'],
                lidWidthMm: $scenario['lidWidthMm'],
                lidDepthMm: $scenario['lidDepthMm'],
                lidHeightMm: $scenario['lidHeightMm'],
            );

            $cases[] = [
                'name' => $name,

                // Formato idêntico ao que o motor TS consome: o material é
                // achatado para cost_per_m2, como a API o entrega ao frontend.
                'material' => [
                    'cost_per_m2' => round($scenario['material']->costPerSquareMeter(), 10),
                    'thickness_mm' => $scenario['material']->thickness_mm,
                ],
                'settings' => $scenario['settings']->only([
                    'energy_tariff_per_kwh', 'machine_hour_rate', 'machine_power_kw',
                    'labor_hour_rate', 'overhead_percent', 'tax_percent',
                ]),
                'spec' => [
                    'box_model' => $scenario['boxModel']->value,
                    'width_mm' => $scenario['widthMm'],
                    'height_mm' => $scenario['heightMm'],
                    'depth_mm' => $scenario['depthMm'],
                    'quantity' => $scenario['quantity'],
                    'waste_percent' => $scenario['wastePercent'],
                    'production_minutes_per_unit' => $scenario['productionMinutes'],
                    'profit_margin_percent' => $scenario['marginPercent'],
                    'pricing_mode' => $scenario['pricingMode'],
                    'lid_width_mm' => $scenario['lidWidthMm'],
                    'lid_depth_mm' => $scenario['lidDepthMm'],
                    'lid_height_mm' => $scenario['lidHeightMm'],
                ],
                'expected' => $engine->calculate($input)->toArray(),
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
     * Cobre cada eixo do cálculo isoladamente e depois combinados: todos os
     * modelos de caixa, as duas unidades de compra, os dois modos de
     * precificação, e os componentes que só aparecem quando ligados (rateio,
     * imposto, espessura).
     *
     * @return array<string, array<string, mixed>>
     */
    private function scenarios(): array
    {
        $base = fn (array $o = []) => [
            'material' => $this->material($o['material'] ?? []),
            'settings' => $this->settings($o['settings'] ?? []),
            'boxModel' => $o['boxModel'] ?? BoxModel::Rsc,
            'widthMm' => $o['widthMm'] ?? 300.0,
            'heightMm' => $o['heightMm'] ?? 200.0,
            'depthMm' => $o['depthMm'] ?? 150.0,
            'quantity' => $o['quantity'] ?? 100,
            'wastePercent' => $o['wastePercent'] ?? 10.0,
            'productionMinutes' => $o['productionMinutes'] ?? 2.5,
            'marginPercent' => $o['marginPercent'] ?? 30.0,
            'pricingMode' => $o['pricingMode'] ?? 'markup',
            'lidWidthMm' => $o['lidWidthMm'] ?? null,
            'lidDepthMm' => $o['lidDepthMm'] ?? null,
            'lidHeightMm' => $o['lidHeightMm'] ?? null,
        ];

        $scenarios = [
            'rsc-padrao' => $base(),
            'modo-margin' => $base(['pricingMode' => 'margin']),
            'margem-zero' => $base(['marginPercent' => 0.0]),
            'margem-alta-markup' => $base(['marginPercent' => 250.0]),
            'margem-99-margin' => $base(['marginPercent' => 99.0, 'pricingMode' => 'margin']),
            'sem-desperdicio' => $base(['wastePercent' => 0.0]),
            'desperdicio-alto' => $base(['wastePercent' => 45.5]),
            'com-rateio' => $base(['settings' => ['overhead_percent' => 12.0]]),
            'com-imposto' => $base(['settings' => ['tax_percent' => 8.0]]),
            'rateio-e-imposto' => $base(['settings' => ['overhead_percent' => 12.0, 'tax_percent' => 8.0]]),
            'material-por-quilo' => $base(['material' => [
                'cost_unit' => MaterialUnit::Kilogram,
                'cost_per_unit' => 8.50,
                'grammage_kg_per_m2' => 0.300,
            ]]),
            'material-espesso' => $base(['material' => ['thickness_mm' => 7.0]]),
            'caixa-minima' => $base(['widthMm' => 10.0, 'heightMm' => 10.0, 'depthMm' => 10.0, 'quantity' => 1]),
            'caixa-maxima' => $base(['widthMm' => 3000.0, 'heightMm' => 3000.0, 'depthMm' => 3000.0, 'quantity' => 1]),
            'dimensoes-fracionadas' => $base(['widthMm' => 237.5, 'heightMm' => 118.3, 'depthMm' => 91.7]),
            'quantidade-alta' => $base(['quantity' => 1000000]),
            'tempo-zero' => $base(['productionMinutes' => 0.0]),
            // Tampa informada pelo usuário: cada eixo e a combinação completa.
            'tampa-manual-completa' => $base([
                'boxModel' => BoxModel::Tray,
                'lidWidthMm' => 340.0, 'lidDepthMm' => 190.0, 'lidHeightMm' => 120.0,
            ]),
            'tampa-so-altura' => $base(['boxModel' => BoxModel::Tray, 'lidHeightMm' => 120.0]),
            'tampa-so-largura' => $base(['boxModel' => BoxModel::Tray, 'lidWidthMm' => 355.5]),
            'tampa-manual-espessa' => $base([
                'boxModel' => BoxModel::Tray,
                'material' => ['thickness_mm' => 7.0],
                'lidWidthMm' => 340.0, 'lidHeightMm' => 95.5,
            ]),
            // Medidas de tampa num modelo sem tampa: devem ser ignoradas.
            'tampa-ignorada-em-saco' => $base([
                'boxModel' => BoxModel::Pouch,
                'lidWidthMm' => 999.0, 'lidHeightMm' => 999.0,
            ]),

            'tudo-combinado' => $base([
                'material' => ['cost_unit' => MaterialUnit::Kilogram, 'cost_per_unit' => 24.0, 'grammage_kg_per_m2' => 0.18, 'thickness_mm' => 0.6],
                'settings' => ['overhead_percent' => 12.0, 'tax_percent' => 8.0],
                'boxModel' => BoxModel::Tray,
                'widthMm' => 412.7, 'heightMm' => 233.1, 'depthMm' => 187.9,
                'quantity' => 7500, 'wastePercent' => 17.5,
                'productionMinutes' => 4.25, 'marginPercent' => 42.0,
                'pricingMode' => 'margin',
            ]),
        ];

        // Um caso por modelo de caixa: cada um tem sua própria planificação.
        foreach (BoxModel::cases() as $model) {
            $scenarios["modelo-{$model->value}"] = $base(['boxModel' => $model]);
            $scenarios["modelo-{$model->value}-espesso"] = $base([
                'boxModel' => $model,
                'material' => ['thickness_mm' => 3.0],
            ]);
        }

        return $scenarios;
    }

    private function material(array $overrides = []): Material
    {
        return new Material([
            'name' => 'Fixture',
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 3.20,
            'thickness_mm' => 0.0,
            ...$overrides,
        ]);
    }

    private function settings(array $overrides = []): CostSetting
    {
        return new CostSetting([
            'energy_tariff_per_kwh' => 0.92,
            'machine_hour_rate' => 45.00,
            'machine_power_kw' => 7.50,
            'labor_hour_rate' => 28.00,
            'overhead_percent' => 0.0,
            'tax_percent' => 0.0,
            'currency' => 'BRL',
            ...$overrides,
        ]);
    }
}

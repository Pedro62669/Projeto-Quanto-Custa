<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\BoxModel;
use App\Models\CostSetting;
use App\Models\Material;

/**
 * Entrada imutável do motor de precificação.
 *
 * Existe para que PricingEngine::calculate() receba UM argumento tipado em vez
 * de doze escalares posicionais — e para que qualquer campo novo (ex.: frete)
 * seja adicionado num único lugar, sem quebrar assinatura.
 */
final readonly class PricingInput
{
    public function __construct(
        public Material $material,
        public CostSetting $settings,
        public BoxModel $boxModel,
        public float $widthMm,
        public float $heightMm,
        public float $depthMm,
        public int $quantity,
        public float $wastePercent,
        public float $productionMinutesPerUnit,
        public float $profitMarginPercent,
        /** 'markup' => custo × (1+m) | 'margin' => custo ÷ (1−m) */
        public string $pricingMode = 'markup',
    ) {}

    /**
     * Constrói a entrada a partir do payload já validado da Request,
     * aplicando os defaults do material e das configurações vigentes.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(array $data, Material $material, CostSetting $settings): self
    {
        $boxModel = BoxModel::from($data['box_model'] ?? BoxModel::Rsc->value);

        return new self(
            material: $material,
            settings: $settings,
            boxModel: $boxModel,
            widthMm: (float) $data['width_mm'],
            heightMm: (float) $data['height_mm'],
            depthMm: (float) $data['depth_mm'],
            quantity: (int) ($data['quantity'] ?? 1),

            // Cada parâmetro opcional cai para o default da sua própria fonte:
            // desperdício vem do material, margem vem da configuração global.
            wastePercent: (float) ($data['waste_percent'] ?? $material->default_waste_percent),
            productionMinutesPerUnit: (float) (
                $data['production_minutes_per_unit'] ?? $boxModel->defaultProductionMinutes()
            ),
            profitMarginPercent: (float) (
                $data['profit_margin_percent'] ?? $settings->default_profit_margin_percent
            ),
            pricingMode: $data['pricing_mode'] ?? 'markup',
        );
    }
}

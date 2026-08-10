<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * Saída imutável do motor de precificação.
 *
 * Os nomes dos campos são exatamente os do JSON da API e os das colunas de
 * `quotes` — assim o Controller persiste com toArray() e o frontend consome
 * sem tradução, eliminando a classe de bug "o campo mudou de nome em um lado".
 */
final readonly class PricingResult
{
    public function __construct(
        // Geometria
        public float $areaM2PerUnit,
        public float $areaM2Total,
        public float $blankWidthMm,
        public float $blankHeightMm,

        /** Área do revestimento por peça (m²); 0 fora da cartonagem rígida. */
        public float $wrapAreaM2PerUnit,

        /** Área do berço por peça (m²); 0 em espuma, que é volumétrica. */
        public float $cradleAreaM2PerUnit,

        /** Volume do berço por peça (m³); só espuma/EVA. */
        public float $cradleVolumeM3PerUnit,

        // Tampa: null nos modelos que não têm peça separada.
        public ?float $lidWidthMm,
        public ?float $lidDepthMm,
        public ?float $lidHeightMm,

        // Custos unitários (R$)
        /** Estrutura: papelão cinza na rígida, a chapa única nas dobradas. */
        public float $materialCost,

        /** Papel de revestimento; 0 fora da cartonagem rígida. */
        public float $wrapCost,

        /** Ímãs, fechos, fitas — cobrados por peça; 0 quando não há. */
        public float $hardwareCost,

        /** Material do berço de acomodação; 0 quando não há. */
        public float $cradleCost,

        /** Minutos que o berço acrescenta à montagem — já somados na mão de obra. */
        public float $cradleMinutes,

        public float $laborCost,
        public float $machineCost,
        public float $energyCost,
        public float $overheadCost,
        public float $unitCost,

        // Preço
        public float $unitPrice,
        public float $totalCost,
        public float $totalPrice,
        public float $profitAmount,
        public float $taxAmount,

        /** Margem líquida efetiva sobre o preço de venda (%) — o número que importa. */
        public float $effectiveMarginPercent,
    ) {}

    /** @return array<string, float|null> */
    public function toArray(): array
    {
        return [
            'area_m2_per_unit' => $this->areaM2PerUnit,
            'area_m2_total' => $this->areaM2Total,
            'blank_width_mm' => $this->blankWidthMm,
            'blank_height_mm' => $this->blankHeightMm,
            'wrap_area_m2_per_unit' => $this->wrapAreaM2PerUnit,
            'cradle_area_m2_per_unit' => $this->cradleAreaM2PerUnit,
            'cradle_volume_m3_per_unit' => $this->cradleVolumeM3PerUnit,
            'lid_width_mm' => $this->lidWidthMm,
            'lid_depth_mm' => $this->lidDepthMm,
            'lid_height_mm' => $this->lidHeightMm,
            'material_cost' => $this->materialCost,
            'wrap_cost' => $this->wrapCost,
            'hardware_cost' => $this->hardwareCost,
            'cradle_cost' => $this->cradleCost,
            'cradle_minutes' => $this->cradleMinutes,
            'labor_cost' => $this->laborCost,
            'machine_cost' => $this->machineCost,
            'energy_cost' => $this->energyCost,
            'overhead_cost' => $this->overheadCost,
            'unit_cost' => $this->unitCost,
            'unit_price' => $this->unitPrice,
            'total_cost' => $this->totalCost,
            'total_price' => $this->totalPrice,
            'profit_amount' => $this->profitAmount,
            'tax_amount' => $this->taxAmount,
            'effective_margin_percent' => $this->effectiveMarginPercent,
        ];
    }
}

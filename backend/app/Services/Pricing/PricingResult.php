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

        // Custos unitários (R$)
        public float $materialCost,
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

    /** @return array<string, float> */
    public function toArray(): array
    {
        return [
            'area_m2_per_unit' => $this->areaM2PerUnit,
            'area_m2_total' => $this->areaM2Total,
            'blank_width_mm' => $this->blankWidthMm,
            'blank_height_mm' => $this->blankHeightMm,
            'material_cost' => $this->materialCost,
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

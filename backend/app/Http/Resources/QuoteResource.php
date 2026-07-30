<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato de saída do orçamento.
 *
 * Existe para desacoplar o schema do banco do schema da API: renomear uma
 * coluna não deve quebrar o frontend. O snapshot completo só é exposto no
 * `show` — em listagens seria payload desperdiçado.
 */
class QuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,

            'client' => [
                'name' => $this->client_name,
                'email' => $this->client_email,
                'document' => $this->client_document,
            ],

            'specification' => [
                'width_mm' => $this->width_mm,
                'height_mm' => $this->height_mm,
                'depth_mm' => $this->depth_mm,
                'box_model' => $this->box_model->value,
                'quantity' => $this->quantity,
                'material' => $this->whenLoaded('material', fn () => [
                    'id' => $this->material->id,
                    'name' => $this->material->name,
                    'color_hex' => $this->material->color_hex,
                ]),
            ],

            'parameters' => [
                'waste_percent' => $this->waste_percent,
                'production_minutes_per_unit' => $this->production_minutes_per_unit,
                'profit_margin_percent' => $this->profit_margin_percent,
                'pricing_mode' => $this->pricing_mode,
            ],

            'costs' => [
                'material' => $this->material_cost,
                'labor' => $this->labor_cost,
                'machine' => $this->machine_cost,
                'energy' => $this->energy_cost,
                'overhead' => $this->overhead_cost,
                'unit_cost' => $this->unit_cost,
            ],

            'pricing' => [
                'unit_price' => $this->unit_price,
                'total_cost' => $this->total_cost,
                'total_price' => $this->total_price,
                'profit_amount' => $this->profit_amount,
            ],

            'area' => [
                'per_unit_m2' => $this->area_m2_per_unit,
                'total_m2' => $this->area_m2_total,
            ],

            'snapshot' => $this->when($request->routeIs('*.show'), $this->pricing_snapshot),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin() ?? false;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'description' => $this->description,

            // O usuário comum precisa do custo já normalizado para simular
            // preços, mas não do preço de compra bruto nem da unidade de
            // negociação com o fornecedor — isso é informação de admin.
            'cost_per_m2' => round($this->costPerSquareMeter(), 4),
            $this->mergeWhen($isAdmin, [
                'cost_unit' => $this->cost_unit->value,
                'cost_per_unit' => $this->cost_per_unit,
                'grammage_kg_per_m2' => $this->grammage_kg_per_m2,
            ]),

            'default_waste_percent' => $this->default_waste_percent,
            'thickness_mm' => $this->thickness_mm,

            // Consumido diretamente pelo renderizador 3D.
            'color_hex' => $this->color_hex,
            'texture_url' => $this->texture_url,

            'is_active' => $this->is_active,
        ];
    }
}

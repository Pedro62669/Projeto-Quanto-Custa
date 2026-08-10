<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Equipment
 */
class EquipmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'purchase_value' => $this->purchase_value,
            'useful_life_months' => $this->useful_life_months,

            /*
             * As derivadas vão explícitas, e não pelo $appends do model.
             *
             * O Resource é o contrato da API: quem lê este arquivo vê o payload
             * inteiro sem precisar abrir o model para descobrir que existem mais
             * dois campos. O $appends continua valendo para toArray()/logs.
             */
            'monthly_depreciation' => $this->monthly_depreciation,
            'annual_depreciation' => $this->annual_depreciation,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Equipment> */
class EquipmentFactory extends Factory
{
    protected $model = Equipment::class;

    /**
     * Números redondos por padrão: R$ 12.000 em 60 meses dá exatamente R$ 200
     * por mês. Um teste que falha precisa acusar a fórmula, não um centavo de
     * arredondamento vindo da fixture.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => TenantFactory::daSuite(),
            'name' => fake()->randomElement(['Vincadeira', 'Guilhotina', 'Laminadora', 'Prensa']).' '.fake()->numerify('##'),
            'purchase_value' => 12000.00,
            'useful_life_months' => 60,
        ];
    }
}

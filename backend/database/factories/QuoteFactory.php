<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Material;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Orçamento já calculado.
 *
 * Os valores monetários são fixos e coerentes entre si, não aleatórios: um
 * `total_price` sorteado que não bate com `unit_price × quantity` faria testes
 * de caixa e de painel financeiro falharem por um motivo que não é o deles.
 * Quem precisa de números específicos os passa no create().
 *
 * A `reference` não vem daqui — o próprio model a gera na criação, por empresa e
 * por ano. Ver Quote::nextReference().
 */
/** @extends Factory<Quote> */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $tenantId = TenantFactory::daSuite();

        return [
            'tenant_id' => $tenantId,
            'user_id' => User::factory()->state(['tenant_id' => $tenantId]),
            'material_id' => Material::factory()->state(['tenant_id' => $tenantId]),

            'box_model' => 'rsc',
            'width_mm' => 300,
            'height_mm' => 200,
            'depth_mm' => 150,
            'quantity' => 100,
            'waste_percent' => 10,
            'production_minutes_per_unit' => 2.5,
            'profit_margin_percent' => 30,

            'client_name' => fake()->name(),

            'area_m2_per_unit' => 0.3,
            'area_m2_total' => 30.0,

            'material_cost' => 8.00,
            'wrap_cost' => 0.0,
            'hardware_cost' => 0.0,
            'labor_cost' => 1.0,
            'machine_cost' => 1.0,
            'energy_cost' => 0.5,
            'overhead_cost' => 0.0,

            'unit_cost' => 10.5,
            'unit_price' => 30.0,
            'total_cost' => 1050.0,
            'total_price' => 3000.0,
            'profit_amount' => 1950.0,

            'pricing_snapshot' => [],
        ];
    }
}

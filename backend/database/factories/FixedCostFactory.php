<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FixedCost;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FixedCost> */
class FixedCostFactory extends Factory
{
    protected $model = FixedCost::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => TenantFactory::daSuite(),
            'name' => fake()->randomElement(['Aluguel', 'Energia', 'Contador', 'Internet', 'Pró-labore']),
            'monthly_amount' => 1000.00,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

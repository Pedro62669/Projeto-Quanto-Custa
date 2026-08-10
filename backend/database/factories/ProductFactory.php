<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Custo 10 e venda 25: margem de exatos 60% sobre a venda. Número redondo
     * para que um teste que falhe acuse a fórmula, não a fixture.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => TenantFactory::daSuite(),
            'name' => fake()->words(2, true),
            'sku' => strtoupper(fake()->unique()->bothify('??-####')),
            'cost_price' => 10.00,
            'sale_price' => 25.00,
            'stock_quantity' => 20,
            'is_active' => true,
        ];
    }
}

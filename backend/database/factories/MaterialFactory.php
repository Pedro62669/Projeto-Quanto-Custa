<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Material> */
class MaterialFactory extends Factory
{
    protected $model = Material::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => TenantFactory::daSuite(),
            'name' => fake()->unique()->words(3, true),
            'type' => MaterialType::Cardboard,
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 3.20,
            'grammage_kg_per_m2' => null,
            'default_waste_percent' => 10.0,
            'thickness_mm' => 0.0,
            'color_hex' => '#C8A06A',
            'is_active' => true,
        ];
    }

    /** Material cotado em quilo — exige gramatura para converter em R$/m². */
    public function byWeight(float $costPerKg = 8.50, float $grammage = 0.300): static
    {
        return $this->state([
            'cost_unit' => MaterialUnit::Kilogram,
            'cost_per_unit' => $costPerKg,
            'grammage_kg_per_m2' => $grammage,
        ]);
    }

    /**
     * Ferragem: ímã, fecho, rebite. Comprada por peça, sem área.
     *
     * É o material que faz `costPerSquareMeter()` lançar exceção — e por isso o
     * que precisa aparecer nos testes de listagem, onde um material sem área
     * convive com os que têm.
     */
    public function hardware(float $costPerPiece = 0.85): static
    {
        return $this->state([
            'type' => MaterialType::Hardware,
            'cost_unit' => MaterialUnit::Piece,
            'cost_per_unit' => $costPerPiece,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

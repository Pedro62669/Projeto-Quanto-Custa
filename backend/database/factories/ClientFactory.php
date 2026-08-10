<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => TenantFactory::daSuite(),
            'name' => fake()->name(),
            'cpf_cnpj' => (string) fake()->unique()->numerify('###########'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '5511'.fake()->numerify('#########'),
            'state' => fake()->randomElement(['SP', 'RJ', 'MG', 'RS', 'BA']),
            'city' => fake()->city(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

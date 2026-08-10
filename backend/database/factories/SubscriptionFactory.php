<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $plano = PlanType::Basic;

        return [
            'tenant_id' => TenantFactory::daSuite(),
            'plan_type' => $plano,
            'status' => PlanStatus::Active,
            'gateway' => 'fake',
            'gateway_subscription_id' => 'fake_sub_'.fake()->unique()->bothify('??????????'),
            'amount' => $plano->monthlyPrice(),
            'started_at' => now(),
            'current_period_ends_at' => now()->addMonthNoOverflow(),
        ];
    }

    /**
     * Contratada há tempo demais para o arrependimento do CDC.
     *
     * Oito dias, não trinta: encostado no limite de propósito, porque é a beirada
     * do prazo que um erro de comparação (`<` no lugar de `<=`) faz falhar.
     */
    public function foraDoPrazo(): static
    {
        return $this->state(fn () => [
            'started_at' => now()->subDays(8),
            'current_period_ends_at' => now()->addDays(22),
        ]);
    }

    public function cancelada(): static
    {
        return $this->state(fn () => [
            'status' => PlanStatus::Canceled,
            'canceled_at' => now(),
        ]);
    }
}

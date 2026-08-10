<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionPaymentStatus;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubscriptionPayment> */
class SubscriptionPaymentFactory extends Factory
{
    protected $model = SubscriptionPayment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantFactory::daSuite(),
            'subscription_id' => SubscriptionFactory::new(),
            'gateway' => 'fake',
            'gateway_payment_id' => 'fake_pay_'.fake()->unique()->bothify('??????????'),
            'amount' => 79.90,
            'status' => SubscriptionPaymentStatus::Paid,
            'paid_at' => now(),
        ];
    }
}

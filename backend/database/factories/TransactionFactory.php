<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => TenantFactory::daSuite(),
            'type' => TransactionType::Entry,
            'category' => TransactionCategory::ProductSale,
            'amount' => 1000.00,
            'description' => fake()->sentence(4),
            'transaction_date' => now(),
        ];
    }

    public function exit(): static
    {
        return $this->state([
            'type' => TransactionType::Exit,
            'category' => TransactionCategory::MaterialPurchase,
        ]);
    }

    public function quoteSale(): static
    {
        return $this->state([
            'type' => TransactionType::Entry,
            'category' => TransactionCategory::QuoteSale,
        ]);
    }
}

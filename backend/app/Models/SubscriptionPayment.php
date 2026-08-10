<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionPaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma cobrança de mensalidade.
 *
 * @property SubscriptionPaymentStatus $status
 * @property float $amount
 */
class SubscriptionPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'subscription_id',
        'gateway', 'gateway_payment_id',
        'amount', 'status', 'paid_at', 'refunded_at', 'refunded_amount',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionPaymentStatus::class,
            'amount' => 'float',
            'refunded_amount' => 'float',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

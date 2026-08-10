<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Enums\SubscriptionPaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O contrato de assinatura entre a empresa e a plataforma.
 *
 * Não usa BelongsToTenant, e isso é deliberado: o TenantScope existe para
 * impedir que uma empresa leia dados de outra, mas quem lê esta tabela é o admin
 * de plataforma, que precisa ver todas. O isolamento do assinante vem do
 * controller — ele só alcança a assinatura pela relação a partir do próprio
 * usuário autenticado, que já é o vínculo correto.
 *
 * @property PlanType $plan_type
 * @property PlanStatus $status
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'plan_type', 'status',
        'gateway', 'gateway_subscription_id', 'amount',
        'started_at', 'trial_ends_at', 'current_period_ends_at', 'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'plan_type' => PlanType::class,
            'status' => PlanStatus::class,
            'amount' => 'float',
            'started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /**
     * Ainda está dentro dos 7 dias de arrependimento do CDC (art. 49)?
     *
     * Dias CORRIDOS a partir da contratação, não úteis: o artigo fala em "sete
     * dias", e a leitura restritiva (úteis) seria contra o consumidor. O prazo é
     * de 7 dias completos, então a comparação usa `<=` — cancelar às 23h59 do
     * sétimo dia ainda dá direito.
     *
     * Conta de `started_at`, nunca de `created_at`: ver a migration.
     */
    public function dentroDoPrazoDeArrependimento(): bool
    {
        $dias = (int) config('billing.dias_de_arrependimento', 7);

        return now()->lessThanOrEqualTo($this->started_at->copy()->addDays($dias));
    }

    /** O pagamento a ser devolvido no arrependimento — o primeiro efetivamente pago. */
    public function pagamentoReembolsavel(): ?SubscriptionPayment
    {
        return $this->payments()
            ->where('status', SubscriptionPaymentStatus::Paid)
            ->orderBy('paid_at')
            ->first();
    }
}

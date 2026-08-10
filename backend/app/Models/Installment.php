<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallmentStatus;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma parcela — a PROMESSA de pagamento.
 *
 * Usa BelongsToTenant apesar de já pender de uma transação escopada, e é
 * proposital: o painel consulta parcelas DIRETAMENTE ("o que vence este mês"),
 * e o TenantScope só filtra a tabela que está sendo consultada. Sem a trait
 * aqui, essa query atravessaria empresas.
 *
 * @property InstallmentStatus $status
 * @property float $amount
 */
class Installment extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'transaction_id', 'installment_number', 'total_installments',
        'amount', 'due_date', 'payment_date', 'status',
    ];

    protected $attributes = [
        'status' => InstallmentStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => InstallmentStatus::class,
            'amount' => 'float',
            'due_date' => 'date',
            'payment_date' => 'date',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Vencida e ainda em aberto.
     *
     * Calculado, nunca gravado: atraso é função da data de hoje, e uma coluna
     * `is_overdue` exigiria um job varrendo a tabela toda noite — mentindo
     * entre duas execuções.
     */
    public function isOverdue(): bool
    {
        return $this->status === InstallmentStatus::Pending
            && $this->due_date->isBefore(now()->startOfDay());
    }

    /**
     * Quita a parcela.
     *
     * A data padrão é HOJE, e não o vencimento: é a diferença entre as duas que
     * separa o caixa realizado do projetado. Assumir que quem paga paga em dia
     * apagaria justamente o atraso que o painel existe para mostrar.
     */
    public function settle(?\DateTimeInterface $paidAt = null): self
    {
        $this->update([
            'status' => InstallmentStatus::Completed,
            'payment_date' => $paidAt ?? now(),
        ]);

        return $this;
    }

    /** Desfaz a quitação — lançamento errado acontece. */
    public function unsettle(): self
    {
        $this->update([
            'status' => InstallmentStatus::Pending,
            'payment_date' => null,
        ]);

        return $this;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', InstallmentStatus::Pending);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', InstallmentStatus::Completed);
    }

    /** Parcelas que VENCEM no mês — a base do fluxo projetado. */
    public function scopeDueIn(Builder $query, int $month, int $year): Builder
    {
        return $query->whereYear('due_date', $year)->whereMonth('due_date', $month);
    }

    /** Parcelas QUITADAS no mês — a base do fluxo realizado. */
    public function scopeSettledIn(Builder $query, int $month, int $year): Builder
    {
        return $query->completed()
            ->whereYear('payment_date', $year)
            ->whereMonth('payment_date', $month);
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Situação de um pagamento de assinatura — o dinheiro que entra PARA A
 * PLATAFORMA, não o do livro caixa do assinante.
 */
enum SubscriptionPaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando',
            self::Paid => 'Pago',
            self::Failed => 'Recusado',
            self::Refunded => 'Reembolsado',
        };
    }

    /** Entra na soma do faturamento? Reembolsado sai; recusado nunca entrou. */
    public function contaNoFaturamento(): bool
    {
        return $this === self::Paid;
    }
}

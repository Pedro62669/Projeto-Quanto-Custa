<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Vocabulário canônico dos eventos de cobrança.
 *
 * Cada gateway nomeia as coisas do seu jeito — Stripe fala `invoice.paid`,
 * Pagar.me fala `charge.paid`. A tradução acontece no driver, e daqui para
 * dentro do sistema só circula este enum. Sem essa camada, trocar de gateway
 * significaria caçar strings do provedor antigo espalhadas pelo domínio.
 */
enum GatewayEventType: string
{
    /** Assinatura contratada ou reativada. */
    case SubscriptionActivated = 'subscription.activated';

    /** Mensalidade paga — estende o período. */
    case PaymentSucceeded = 'payment.succeeded';

    /** Cobrança recusada — vira past_due, não corta o acesso na hora. */
    case PaymentFailed = 'payment.failed';

    /** Cancelamento originado no gateway (chargeback, cancelamento pelo banco). */
    case SubscriptionCanceled = 'subscription.canceled';

    /** Estorno confirmado pelo gateway. */
    case PaymentRefunded = 'payment.refunded';
}

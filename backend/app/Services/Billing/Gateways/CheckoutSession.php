<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use Illuminate\Support\Carbon;

/**
 * A sessão de pagamento criada no gateway.
 *
 * O que volta para cá é uma URL, e só. O cartão é digitado no ambiente hospedado
 * do provedor — é o que mantém este servidor fora do escopo do PCI-DSS, e a
 * razão de o contrato PaymentGateway não ter nenhum método que receba número de
 * cartão.
 */
final readonly class CheckoutSession
{
    public function __construct(
        /** Para onde redirecionar o navegador do assinante. */
        public string $url,

        /** Id da sessão no gateway — o que volta nos webhooks. */
        public string $gatewaySubscriptionId,

        public ?Carbon $expiraEm = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'gateway_subscription_id' => $this->gatewaySubscriptionId,
            'expira_em' => $this->expiraEm?->toIso8601String(),
        ];
    }
}

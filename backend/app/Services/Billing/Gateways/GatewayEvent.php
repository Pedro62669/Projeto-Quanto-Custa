<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use App\Enums\GatewayEventType;
use App\Enums\PlanType;
use Illuminate\Support\Carbon;

/**
 * Um evento de cobrança já traduzido para o vocabulário do sistema.
 *
 * Readonly porque é um fato consumado: o gateway avisou que algo aconteceu, e
 * nada do lado de cá deveria poder reescrever o que ele disse antes de aplicar.
 */
final readonly class GatewayEvent
{
    public function __construct(
        /** Id do evento NO GATEWAY — é a chave da idempotência. */
        public string $externalId,
        public GatewayEventType $type,
        public ?string $gatewaySubscriptionId = null,
        public ?string $gatewayPaymentId = null,
        public ?float $amount = null,
        public ?PlanType $planType = null,
        public ?Carbon $periodEndsAt = null,
        /** Identificação da empresa, quando o gateway a devolve nos metadados. */
        public ?int $tenantId = null,
    ) {}
}

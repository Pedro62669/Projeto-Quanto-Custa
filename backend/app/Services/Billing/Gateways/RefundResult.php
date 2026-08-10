<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateways;

/**
 * Resultado de um pedido de estorno.
 *
 * Objeto em vez de bool, e sem exceção no caminho de falha: um estorno recusado
 * pelo gateway (saldo insuficiente, cobrança antiga demais) é um resultado
 * PREVISTO, e quem chama precisa da mensagem para mostrar ao cliente. Exceção
 * ficaria para o que não se previu.
 */
final readonly class RefundResult
{
    private function __construct(
        public bool $sucesso,
        public ?string $gatewayRefundId,
        public float $amount,
        public string $mensagem,
    ) {}

    public static function ok(string $gatewayRefundId, float $amount): self
    {
        return new self(true, $gatewayRefundId, $amount, 'Estorno confirmado pelo gateway.');
    }

    public static function falha(string $mensagem): self
    {
        return new self(false, null, 0.0, $mensagem);
    }
}

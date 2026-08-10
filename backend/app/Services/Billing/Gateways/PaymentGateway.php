<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use App\Enums\PlanType;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;

/**
 * O contrato mínimo que a plataforma precisa de um gateway.
 *
 * Três métodos, e nenhum deles cria cobrança: o checkout em si acontece no
 * ambiente hospedado do provedor (Stripe Checkout, link de pagamento do
 * Pagar.me), que é onde o cartão pode ser digitado sem que este servidor entre
 * no escopo do PCI-DSS. O que volta para cá é webhook — e é por isso que a
 * verificação de assinatura está no contrato, e não como detalhe do driver.
 *
 * Trocar de provedor é escrever uma classe. O domínio inteiro — a regra dos 7
 * dias, a extensão de período, a suspensão — não sabe qual gateway está atrás.
 */
interface PaymentGateway
{
    /** Identificador curto, gravado em `subscriptions.gateway`. */
    public function name(): string;

    /**
     * Abre uma sessão de pagamento e devolve para onde mandar o navegador.
     *
     * Note o que ela NÃO recebe: nada de cartão. O provedor coleta o dado
     * sensível na página dele e nos avisa por webhook — é assim que a assinatura
     * é ativada, e é o que mantém este servidor fora do escopo do PCI-DSS.
     *
     * O `tenant` viaja nos metadados da sessão porque é ele que o webhook de
     * ativação precisa para saber de quem é a assinatura. Ver
     * SubscriptionManager::localizaAssinatura().
     */
    public function criaCheckout(Tenant $tenant, PlanType $plano): CheckoutSession;

    /**
     * Solicita o estorno de um pagamento.
     *
     * `$amount` null = total. Devolve RefundResult em vez de lançar: recusa é
     * resultado esperado, não excepcional.
     */
    public function refund(SubscriptionPayment $payment, ?float $amount = null): RefundResult;

    /**
     * A requisição veio mesmo do gateway?
     *
     * Sem isto, o endpoint de webhook é um botão público de "me promova para
     * Pro": basta um POST com o corpo certo. É a checagem mais importante deste
     * módulo inteiro.
     */
    public function verificaAssinatura(string $payload, ?string $assinatura): bool;

    /**
     * Traduz o corpo do webhook para o vocabulário do sistema.
     *
     * Devolve null quando o evento não interessa — gateways emitem dezenas de
     * tipos, e ignorar o que não se usa é mais seguro do que tentar tratar tudo.
     *
     * @param  array<string, mixed>  $payload
     */
    public function interpretaEvento(array $payload): ?GatewayEvent;
}

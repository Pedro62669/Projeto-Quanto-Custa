<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use App\Enums\GatewayEventType;
use App\Enums\PlanType;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Driver de desenvolvimento e de teste.
 *
 * Não é um stub vazio: ele implementa de verdade a parte que o domínio depende
 * para estar correta — a verificação HMAC da assinatura do webhook e o formato
 * canônico do evento. Só a chamada de rede é que não existe.
 *
 * Isso é proposital. O erro clássico de um "gateway falso" é aceitar qualquer
 * requisição, o que faz os testes de webhook passarem sem nunca exercitar a
 * trava de autenticidade — e o dia em que o driver real entra é o dia em que se
 * descobre que ninguém testou o caminho da assinatura inválida.
 *
 * Trocar por Stripe ou Pagar.me: escrever a classe irmã, mudar `billing.driver`.
 */
class FakeGateway implements PaymentGateway
{
    public function __construct(private readonly string $segredo) {}

    public function name(): string
    {
        return 'fake';
    }

    /**
     * Sessão de checkout simulada.
     *
     * A URL aponta para o próprio frontend com os parâmetros que a página de
     * retorno espera — assim o fluxo do navegador pode ser percorrido inteiro em
     * desenvolvimento. Quem "paga" é o comando `billing:simular-pagamento`, que
     * dispara o webhook de ativação com assinatura HMAC válida.
     */
    public function criaCheckout(Tenant $tenant, PlanType $plano): CheckoutSession
    {
        $id = 'fake_sub_'.Str::lower(Str::random(20));

        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/assinatura/simulacao?'.http_build_query([
                'sessao' => $id,
                'plano' => $plano->value,
                'empresa' => $tenant->id,
                'valor' => $plano->monthlyPrice(),
            ]);

        return new CheckoutSession(
            url: $url,
            gatewaySubscriptionId: $id,
            expiraEm: now()->addHour(),
        );
    }

    public function refund(SubscriptionPayment $payment, ?float $amount = null): RefundResult
    {
        $valor = $amount ?? $payment->amount;

        if ($valor <= 0) {
            return RefundResult::falha('Valor de estorno precisa ser positivo.');
        }

        if ($valor > $payment->amount) {
            return RefundResult::falha('Estorno maior que o valor pago.');
        }

        return RefundResult::ok('fake_re_'.Str::lower(Str::random(20)), round($valor, 2));
    }

    /**
     * HMAC-SHA256 do corpo cru, comparado em tempo constante.
     *
     * `hash_equals` e não `===`: comparação de string curto-circuita no primeiro
     * byte diferente, e essa diferença de tempo é mensurável pela rede. É o
     * mesmo cuidado que o AccessLog já toma ao assinar registros.
     */
    public function verificaAssinatura(string $payload, ?string $assinatura): bool
    {
        if ($assinatura === null || $assinatura === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $this->segredo), $assinatura);
    }

    /** Gera a assinatura de um corpo — usado pelos testes e pelo ambiente local. */
    public function assina(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->segredo);
    }

    public function interpretaEvento(array $payload): ?GatewayEvent
    {
        $tipo = GatewayEventType::tryFrom((string) ($payload['type'] ?? ''));
        $id = (string) ($payload['id'] ?? '');

        if ($tipo === null || $id === '') {
            return null;
        }

        $dados = $payload['data'] ?? [];

        return new GatewayEvent(
            externalId: $id,
            type: $tipo,
            gatewaySubscriptionId: isset($dados['subscription_id']) ? (string) $dados['subscription_id'] : null,
            gatewayPaymentId: isset($dados['payment_id']) ? (string) $dados['payment_id'] : null,
            amount: isset($dados['amount']) ? (float) $dados['amount'] : null,
            planType: PlanType::tryFrom((string) ($dados['plan'] ?? '')),
            periodEndsAt: isset($dados['period_ends_at'])
                ? Carbon::parse((string) $dados['period_ends_at'])
                : null,
            tenantId: isset($dados['tenant_id']) ? (int) $dados['tenant_id'] : null,
        );
    }
}

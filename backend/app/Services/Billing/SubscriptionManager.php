<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\GatewayEventType;
use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Enums\SubscriptionPaymentStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Services\Billing\Gateways\GatewayEvent;
use App\Services\Billing\Gateways\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * O ciclo de vida da assinatura: cancelamento, arrependimento e webhooks.
 *
 * Toda a regra mora aqui, e não nos controllers, porque os dois caminhos que
 * mudam o estado de uma assinatura — o usuário clicando em cancelar e o gateway
 * avisando por webhook — precisam chegar exatamente no mesmo lugar. Se cada um
 * escrevesse suas próprias linhas, o estorno feito pelo botão e o estorno
 * confirmado pelo webhook produziriam registros diferentes do mesmo fato.
 */
class SubscriptionManager
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Cancela a assinatura, aplicando o direito de arrependimento do CDC.
     *
     * Art. 49 do Código de Defesa do Consumidor: contratação fora do
     * estabelecimento comercial — e toda venda deste SaaS é pela internet — dá
     * sete dias corridos para desistir, com devolução integral do que foi pago.
     *
     * A distinção que o sistema faz, e que o prazo torna necessária:
     *
     *  • DENTRO dos 7 dias, com pagamento feito → estorna e encerra o acesso
     *    hoje. O dinheiro voltou; manter o acesso seria entregar o serviço de
     *    graça.
     *  • FORA dos 7 dias → nada de estorno, mas o acesso segue até o fim do
     *    período JÁ PAGO. Cortar na hora seria cobrar por um mês e entregar
     *    duas semanas — o que a lei chama de enriquecimento sem causa, e o
     *    cliente chama de golpe.
     */
    public function cancelar(Subscription $assinatura): CancelamentoResult
    {
        if ($assinatura->status === PlanStatus::Canceled) {
            throw new RuntimeException('Esta assinatura já está cancelada.');
        }

        $pagamento = $assinatura->pagamentoReembolsavel();
        $temDireito = $assinatura->dentroDoPrazoDeArrependimento() && $pagamento !== null;

        $estorno = null;

        if ($temDireito) {
            /*
             * A chamada ao gateway acontece FORA da transação de banco.
             *
             * Uma chamada de rede dentro de uma transação segura o lock pelo
             * tempo do provedor responder — e, pior, se o COMMIT falhar depois
             * de o estorno ter sido aceito, o dinheiro voltou sem registro
             * nenhum de que voltou. Aqui, o pior caso é o inverso: estorno
             * confirmado e banco não gravado, que o webhook `payment.refunded`
             * reconcilia depois, de forma idempotente. Perder o registro de um
             * fato é recuperável; inventar um fato que não houve, não.
             */
            $estorno = $this->gateway->refund($pagamento);

            if (! $estorno->sucesso) {
                Log::warning('Estorno recusado pelo gateway', [
                    'subscription_id' => $assinatura->id,
                    'payment_id' => $pagamento->id,
                    'motivo' => $estorno->mensagem,
                ]);

                throw new RuntimeException(
                    'Não foi possível processar o estorno agora: '.$estorno->mensagem
                    .' Sua assinatura NÃO foi cancelada — tente de novo ou fale com o suporte.'
                );
            }
        }

        $agora = now();

        /*
         * Fim do acesso. Com estorno, é agora. Sem estorno, é o fim do período
         * pago — e `?? $agora` cobre o cancelamento durante o teste gratuito,
         * onde não há período pago nenhum a respeitar.
         */
        $acessoAte = $estorno !== null
            ? $agora
            : ($assinatura->current_period_ends_at ?? $agora);

        DB::transaction(function () use ($assinatura, $pagamento, $estorno, $agora, $acessoAte): void {
            if ($estorno !== null && $pagamento !== null) {
                $pagamento->forceFill([
                    'status' => SubscriptionPaymentStatus::Refunded,
                    'refunded_at' => $agora,
                    'refunded_amount' => $estorno->amount,
                ])->save();
            }

            $assinatura->forceFill([
                'status' => PlanStatus::Canceled,
                'canceled_at' => $agora,
            ])->save();

            $assinatura->tenant->forceFill([
                'plan_status' => PlanStatus::Canceled,
                'subscription_ends_at' => $acessoAte,

                /*
                 * Rebaixa para o gratuito. O histórico do que foi contratado
                 * fica em `subscriptions`; o que a coluna do tenant guarda é o
                 * que vale AGORA — e o que vale agora, sem assinatura, é Free.
                 * Sem isto, uma conta cancelada e depois reativada
                 * administrativamente voltaria com cotas de Pro sem pagar.
                 */
                'plan_type' => PlanType::Free,
            ])->save();
        });

        return new CancelamentoResult(
            reembolsado: $estorno !== null,
            valorReembolsado: $estorno?->amount ?? 0.0,
            acessoAte: $acessoAte,
            mensagem: $estorno !== null
                ? 'Assinatura cancelada e valor estornado integralmente, conforme o art. 49 do CDC. '
                    .'O crédito aparece na fatura em até dois ciclos, prazo do seu banco.'
                : 'Assinatura cancelada. Seu acesso continua até '
                    .$acessoAte->format('d/m/Y').', fim do período já pago.',
        );
    }

    /**
     * Aplica um evento vindo do gateway.
     *
     * Sempre idempotente: cada ramo é escrito para poder rodar duas vezes com o
     * mesmo resultado. A trava de `webhook_events` já barra a repetição do mesmo
     * evento, mas eventos DIFERENTES podem descrever o mesmo fato (um
     * `payment.succeeded` e um `invoice.paid` do mesmo ciclo), e a idempotência
     * aqui é a segunda rede.
     */
    public function aplicarEvento(GatewayEvent $evento): void
    {
        $assinatura = $this->localizaAssinatura($evento);

        if ($assinatura === null) {
            Log::warning('Webhook sem assinatura correspondente', [
                'external_id' => $evento->externalId,
                'type' => $evento->type->value,
            ]);

            return;
        }

        DB::transaction(function () use ($evento, $assinatura): void {
            match ($evento->type) {
                GatewayEventType::SubscriptionActivated => $this->ativa($assinatura, $evento),
                GatewayEventType::PaymentSucceeded => $this->registraPagamento($assinatura, $evento),
                GatewayEventType::PaymentFailed => $this->marcaInadimplente($assinatura),
                GatewayEventType::SubscriptionCanceled => $this->cancelaPeloGateway($assinatura),
                GatewayEventType::PaymentRefunded => $this->registraEstorno($assinatura, $evento),
            };
        });
    }

    private function localizaAssinatura(GatewayEvent $evento): ?Subscription
    {
        if ($evento->gatewaySubscriptionId !== null) {
            $assinatura = Subscription::query()
                ->where('gateway', $this->gateway->name())
                ->where('gateway_subscription_id', $evento->gatewaySubscriptionId)
                ->first();

            if ($assinatura !== null) {
                return $assinatura;
            }
        }

        /*
         * Primeira ativação: a assinatura ainda não existe deste lado. Cria a
         * partir dos metadados, que é onde o checkout carimba o tenant.
         */
        if ($evento->type === GatewayEventType::SubscriptionActivated && $evento->tenantId !== null) {
            $tenant = Tenant::find($evento->tenantId);

            if ($tenant === null) {
                return null;
            }

            return Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_type' => $evento->planType ?? PlanType::Basic,
                'status' => PlanStatus::Active,
                'gateway' => $this->gateway->name(),
                'gateway_subscription_id' => $evento->gatewaySubscriptionId,
                'amount' => $evento->amount ?? ($evento->planType?->monthlyPrice() ?? 0.0),
                'started_at' => now(),
                'current_period_ends_at' => $evento->periodEndsAt,
            ]);
        }

        return null;
    }

    private function ativa(Subscription $assinatura, GatewayEvent $evento): void
    {
        $plano = $evento->planType ?? $assinatura->plan_type;
        $fimDoPeriodo = $evento->periodEndsAt ?? $assinatura->current_period_ends_at;

        $assinatura->forceFill([
            'status' => PlanStatus::Active,
            'plan_type' => $plano,
            'current_period_ends_at' => $fimDoPeriodo,
            'canceled_at' => null,
        ])->save();

        $assinatura->tenant->forceFill([
            'plan_type' => $plano,
            'plan_status' => PlanStatus::Active,
            'subscription_ends_at' => $fimDoPeriodo,
        ])->save();
    }

    private function registraPagamento(Subscription $assinatura, GatewayEvent $evento): void
    {
        /*
         * updateOrCreate pela chave do gateway: se o mesmo pagamento chegar de
         * novo por outro tipo de evento, atualiza em vez de duplicar — e
         * duplicar aqui inflaria o faturamento do painel de plataforma.
         */
        SubscriptionPayment::updateOrCreate(
            [
                'gateway' => $this->gateway->name(),
                'gateway_payment_id' => $evento->gatewayPaymentId,
            ],
            [
                'tenant_id' => $assinatura->tenant_id,
                'subscription_id' => $assinatura->id,
                'amount' => $evento->amount ?? $assinatura->amount,
                'status' => SubscriptionPaymentStatus::Paid,
                'paid_at' => now(),
            ],
        );

        /*
         * addMonthNoOverflow: assinou dia 31 de janeiro, o próximo vencimento é
         * 28/02 e não 03/03. Com overflow, quem assina no fim do mês vai
         * empurrando a data um pouco a cada ciclo e paga menos vezes por ano —
         * mesmo cuidado que o FinancialEngine já toma nas parcelas.
         */
        $fimDoPeriodo = $evento->periodEndsAt
            ?? ($assinatura->current_period_ends_at?->copy() ?? now())->addMonthNoOverflow();

        $assinatura->forceFill([
            'status' => PlanStatus::Active,
            'current_period_ends_at' => $fimDoPeriodo,
        ])->save();

        $assinatura->tenant->forceFill([
            'plan_status' => PlanStatus::Active,
            'subscription_ends_at' => $fimDoPeriodo,
        ])->save();
    }

    /**
     * Fatura recusada.
     *
     * Marca e só. Não mexe em `subscription_ends_at` de propósito: cartão
     * recusado é quase sempre limite estourado ou validade vencida, não
     * desistência. Quem corta o acesso é a data de fim do período que já foi
     * pago — a régua de cobrança acontece sozinha, sem punir quem trocou de
     * banco no meio do mês.
     */
    private function marcaInadimplente(Subscription $assinatura): void
    {
        $assinatura->forceFill(['status' => PlanStatus::PastDue])->save();
        $assinatura->tenant->forceFill(['plan_status' => PlanStatus::PastDue])->save();
    }

    private function cancelaPeloGateway(Subscription $assinatura): void
    {
        $acessoAte = $assinatura->current_period_ends_at ?? now();

        $assinatura->forceFill([
            'status' => PlanStatus::Canceled,
            'canceled_at' => $assinatura->canceled_at ?? now(),
        ])->save();

        $assinatura->tenant->forceFill([
            'plan_status' => PlanStatus::Canceled,
            'plan_type' => PlanType::Free,
            'subscription_ends_at' => $acessoAte,
        ])->save();
    }

    /**
     * Estorno confirmado pelo gateway.
     *
     * É também o caminho de reconciliação do cancelamento feito pelo botão: se o
     * estorno saiu mas a gravação falhou, este evento fecha a lacuna. Por isso
     * ele não pode presumir que o pagamento ainda está como "pago".
     */
    private function registraEstorno(Subscription $assinatura, GatewayEvent $evento): void
    {
        $pagamento = SubscriptionPayment::query()
            ->where('gateway', $this->gateway->name())
            ->where('gateway_payment_id', $evento->gatewayPaymentId)
            ->first();

        if ($pagamento === null) {
            return;
        }

        if ($pagamento->status !== SubscriptionPaymentStatus::Refunded) {
            $pagamento->forceFill([
                'status' => SubscriptionPaymentStatus::Refunded,
                'refunded_at' => now(),
                'refunded_amount' => $evento->amount ?? $pagamento->amount,
            ])->save();
        }

        $this->cancelaPeloGateway($assinatura);

        // Com o dinheiro devolvido, o acesso termina hoje — não no fim do ciclo.
        $assinatura->tenant->forceFill(['subscription_ends_at' => now()])->save();
    }
}

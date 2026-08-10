<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\GatewayEventType;
use App\Enums\PlanType;
use App\Models\Tenant;
use App\Services\Billing\Gateways\FakeGateway;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * "Paga" uma assinatura no gateway falso.
 *
 * O que ele resolve: sem conta em Stripe ou Pagar.me, não há como percorrer
 * cadastro → checkout → cota liberada de ponta a ponta. Testes cobrem cada
 * pedaço isoladamente, mas o caminho inteiro só se prova andando nele, e é
 * andando nele que se descobre que uma peça não encaixa na outra.
 *
 * Passa pelo SubscriptionManager, o MESMO que o webhook real usa — não escreve
 * nas tabelas direto. Um atalho que gravasse `subscriptions` à mão simularia um
 * estado que o fluxo verdadeiro nunca produz, e o ambiente local deixaria de
 * ensinar qualquer coisa sobre produção.
 *
 * Recusa-se a rodar fora do driver falso: o comando existe para o
 * desenvolvimento, e apontá-lo para um gateway de verdade inventaria receita.
 */
class SimulaPagamento extends Command
{
    protected $signature = 'billing:simular-pagamento
                            {tenant : Id da empresa}
                            {plano=basic : basic ou pro}';

    protected $description = 'Ativa uma assinatura no gateway falso, como se o pagamento tivesse sido confirmado';

    public function handle(PaymentGateway $gateway, SubscriptionManager $manager): int
    {
        if (! $gateway instanceof FakeGateway) {
            $this->error('Este comando só roda com BILLING_DRIVER=fake. '
                .'Com gateway real, a ativação tem que vir de um pagamento real.');

            return self::FAILURE;
        }

        $tenant = Tenant::find((int) $this->argument('tenant'));

        if ($tenant === null) {
            $this->error('Empresa não encontrada.');

            return self::FAILURE;
        }

        $plano = PlanType::tryFrom((string) $this->argument('plano'));

        if ($plano === null || ! $plano->isPaid()) {
            $this->error('Plano inválido. Use basic ou pro.');

            return self::FAILURE;
        }

        $sessao = $gateway->criaCheckout($tenant, $plano);
        $fimDoPeriodo = now()->addMonthNoOverflow();

        /*
         * Dois eventos, na ordem em que um gateway real os manda: primeiro a
         * assinatura passa a existir, depois a cobrança é confirmada. Mandar só
         * o segundo deixaria o SubscriptionManager sem assinatura para localizar
         * — e é exatamente esse encadeamento que o comando serve para exercitar.
         */
        $manager->aplicarEvento($gateway->interpretaEvento([
            'id' => 'evt_'.Str::lower(Str::random(16)),
            'type' => GatewayEventType::SubscriptionActivated->value,
            'data' => [
                'subscription_id' => $sessao->gatewaySubscriptionId,
                'tenant_id' => $tenant->id,
                'plan' => $plano->value,
                'amount' => $plano->monthlyPrice(),
                'period_ends_at' => $fimDoPeriodo->toIso8601String(),
            ],
        ]));

        $manager->aplicarEvento($gateway->interpretaEvento([
            'id' => 'evt_'.Str::lower(Str::random(16)),
            'type' => GatewayEventType::PaymentSucceeded->value,
            'data' => [
                'subscription_id' => $sessao->gatewaySubscriptionId,
                'payment_id' => 'pay_'.Str::lower(Str::random(16)),
                'amount' => $plano->monthlyPrice(),
                'period_ends_at' => $fimDoPeriodo->toIso8601String(),
            ],
        ]));

        $tenant->refresh();

        $this->info(sprintf(
            '%s agora está no plano %s (%s), válido até %s.',
            $tenant->name,
            $tenant->plan_type->label(),
            $tenant->plan_status->label(),
            $tenant->subscription_ends_at?->format('d/m/Y') ?? '—',
        ));

        return self::SUCCESS;
    }
}

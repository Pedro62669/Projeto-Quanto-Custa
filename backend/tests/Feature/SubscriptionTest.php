<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GatewayEventType;
use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\UserRole;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Gateways\FakeGateway;
use App\Services\Billing\Gateways\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Assinatura: arrependimento do CDC, webhooks e a fronteira com o livro caixa.
 *
 * Os dois riscos que estes testes existem para conter são de naturezas opostas.
 * Um é jurídico — o art. 49 do CDC dá sete dias de arrependimento com devolução
 * integral, e um sistema que não devolve gera passivo. O outro é operacional —
 * webhook reenviado é regra, não exceção, e aplicar o mesmo evento duas vezes
 * estende período ou estorna dinheiro já devolvido.
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->noPlano(PlanType::Basic)->create();

        $this->admin = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::Admin,
        ]);
    }

    private function assinatura(bool $foraDoPrazo = false): Subscription
    {
        $factory = Subscription::factory()->state(['tenant_id' => $this->empresa->id]);

        if ($foraDoPrazo) {
            $factory = $factory->foraDoPrazo();
        }

        $assinatura = $factory->create();

        SubscriptionPayment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'subscription_id' => $assinatura->id,
            'amount' => $assinatura->amount,
        ]);

        return $assinatura;
    }

    /* ── Arrependimento (CDC art. 49) ──────────────────────────────────── */

    #[Test]
    public function cancelar_dentro_de_sete_dias_estorna_o_valor_integral(): void
    {
        $assinatura = $this->assinatura();

        $this->actingAs($this->admin)
            ->postJson('/api/subscriptions/cancel', ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.reembolsado', true)
            ->assertJsonPath('data.valor_reembolsado', $assinatura->amount);

        $pagamento = SubscriptionPayment::query()->sole();

        $this->assertSame(SubscriptionPaymentStatus::Refunded, $pagamento->status);
        $this->assertSame($assinatura->amount, $pagamento->refunded_amount);
        $this->assertNotNull($pagamento->refunded_at);
    }

    #[Test]
    public function o_setimo_dia_ainda_da_direito_ao_estorno(): void
    {
        /*
         * A beirada do prazo. "Sete dias" são sete dias completos: cancelar às
         * 23h59 do sétimo ainda vale. É aqui que um `<` no lugar de `<=` cria
         * passivo jurídico sem que nenhum outro teste perceba.
         */
        $assinatura = $this->assinatura();
        $assinatura->forceFill(['started_at' => now()->subDays(7)->addMinutes(1)])->save();

        $this->actingAs($this->admin)
            ->postJson('/api/subscriptions/cancel', ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.reembolsado', true);
    }

    #[Test]
    public function com_estorno_o_acesso_termina_hoje(): void
    {
        $this->assinatura();

        $this->actingAs($this->admin)
            ->postJson('/api/subscriptions/cancel', ['password' => 'password'])
            ->assertOk();

        // O dinheiro voltou; manter o acesso seria entregar o serviço de graça.
        $this->assertFalse($this->empresa->fresh()->acessoLiberado());
    }

    #[Test]
    public function cancelar_fora_do_prazo_nao_estorna_mas_preserva_o_periodo_pago(): void
    {
        $assinatura = $this->assinatura(foraDoPrazo: true);

        $this->actingAs($this->admin)
            ->postJson('/api/subscriptions/cancel', ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.reembolsado', false);

        /*
         * A outra metade da regra, e a que se esquece com mais frequência:
         * cortar o acesso na hora seria cobrar por um mês e entregar duas
         * semanas. O período já pago é devido.
         */
        $this->assertTrue($this->empresa->fresh()->acessoLiberado());

        $this->assertSame(
            $assinatura->current_period_ends_at->toDateString(),
            $this->empresa->fresh()->subscription_ends_at->toDateString(),
        );

        $this->assertSame(
            SubscriptionPaymentStatus::Paid,
            SubscriptionPayment::query()->sole()->status,
            'Fora do prazo, nada é devolvido.',
        );
    }

    #[Test]
    public function cancelar_rebaixa_a_empresa_para_o_plano_gratuito(): void
    {
        $this->assinatura();

        $this->actingAs($this->admin)
            ->postJson('/api/subscriptions/cancel', ['password' => 'password'])
            ->assertOk();

        $recarregada = $this->empresa->fresh();

        // Sem isto, uma conta cancelada e reativada administrativamente voltaria
        // com as cotas do plano pago sem pagar por elas.
        $this->assertSame(PlanType::Free, $recarregada->plan_type);
        $this->assertSame(PlanStatus::Canceled, $recarregada->plan_status);
    }

    #[Test]
    public function cancelar_exige_a_senha_atual(): void
    {
        $this->assinatura();

        // Endpoint que movimenta dinheiro não pode ser alcançável só com um
        // token de sessão roubado.
        $this->actingAs($this->admin)
            ->postJson('/api/subscriptions/cancel', ['password' => 'errada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame(PlanStatus::Active, Subscription::query()->sole()->status);
    }

    #[Test]
    public function usuario_comum_nao_cancela_a_assinatura_da_empresa(): void
    {
        $this->assinatura();

        $comum = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::User,
        ]);

        $this->actingAs($comum)
            ->postJson('/api/subscriptions/cancel', ['password' => 'password'])
            ->assertForbidden();
    }

    #[Test]
    public function a_assinatura_de_uma_empresa_nao_alcanca_a_outra(): void
    {
        $this->assinatura();

        $vizinha = Tenant::factory()->create();
        $adminVizinho = User::factory()->create([
            'tenant_id' => $vizinha->id,
            'role' => UserRole::Admin,
        ]);

        /*
         * O vínculo vem do usuário autenticado, nunca de um id do payload. Se
         * viesse da requisição, cancelar a assinatura do concorrente seria um
         * POST.
         */
        $this->actingAs($adminVizinho)
            ->postJson('/api/subscriptions/cancel', ['password' => 'password'])
            ->assertUnprocessable();

        $this->assertSame(PlanStatus::Active, Subscription::query()->sole()->status);
    }

    #[Test]
    public function o_endpoint_de_assinatura_informa_o_prazo_de_arrependimento(): void
    {
        $this->assinatura();

        // O CDC exige informação clara e ostensiva. Um botão de cancelar que não
        // diz "você ainda tem direito ao dinheiro de volta" cumpre a letra e
        // falha o propósito.
        $this->actingAs($this->admin)
            ->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.assinatura.arrependimento_disponivel', true);
    }

    /* ── Webhooks ──────────────────────────────────────────────────────── */

    /** @param  array<string, mixed>  $payload */
    private function enviaWebhook(array $payload, ?string $assinaturaInvalida = null): TestResponse
    {
        $corpo = json_encode($payload, JSON_THROW_ON_ERROR);

        /** @var FakeGateway $gateway */
        $gateway = app(PaymentGateway::class);

        return $this->call(
            'POST',
            '/api/webhooks/billing',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_'.str_replace('-', '_', mb_strtoupper((string) config('billing.signature_header'))) => $assinaturaInvalida ?? $gateway->assina($corpo),
            ],
            content: $corpo,
        );
    }

    #[Test]
    public function webhook_sem_assinatura_valida_e_recusado(): void
    {
        $assinatura = $this->assinatura();

        /*
         * A barreira mais importante do módulo. A rota é pública — quem chama é
         * um servidor do gateway, sem token de usuário. Sem a verificação HMAC,
         * este endpoint seria um botão anônimo de "me promova para Pro".
         */
        $this->enviaWebhook([
            'id' => 'evt_forjado',
            'type' => GatewayEventType::SubscriptionActivated->value,
            'data' => [
                'subscription_id' => $assinatura->gateway_subscription_id,
                'plan' => PlanType::Pro->value,
            ],
        ], assinaturaInvalida: 'assinatura-inventada')->assertUnauthorized();

        $this->assertSame(PlanType::Basic, $this->empresa->fresh()->plan_type);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    #[Test]
    public function webhook_de_pagamento_estende_o_periodo(): void
    {
        $assinatura = $this->assinatura();
        $novoFim = now()->addMonthNoOverflow()->addMonthNoOverflow();

        $this->enviaWebhook([
            'id' => 'evt_pagamento_1',
            'type' => GatewayEventType::PaymentSucceeded->value,
            'data' => [
                'subscription_id' => $assinatura->gateway_subscription_id,
                'payment_id' => 'pay_novo_ciclo',
                'amount' => 79.90,
                'period_ends_at' => $novoFim->toIso8601String(),
            ],
        ])->assertOk();

        $this->assertSame(
            $novoFim->toDateString(),
            $this->empresa->fresh()->subscription_ends_at->toDateString(),
        );

        $this->assertSame(
            SubscriptionPaymentStatus::Paid,
            SubscriptionPayment::query()->where('gateway_payment_id', 'pay_novo_ciclo')->sole()->status,
        );
    }

    #[Test]
    public function o_mesmo_webhook_reenviado_nao_e_aplicado_duas_vezes(): void
    {
        $assinatura = $this->assinatura();
        $fim = now()->addMonths(2);

        $evento = [
            'id' => 'evt_repetido',
            'type' => GatewayEventType::PaymentSucceeded->value,
            'data' => [
                'subscription_id' => $assinatura->gateway_subscription_id,
                'payment_id' => 'pay_repetido',
                'amount' => 79.90,
                'period_ends_at' => $fim->toIso8601String(),
            ],
        ];

        $this->enviaWebhook($evento)->assertOk();

        /*
         * Todo gateway reenvia quando não recebe 2xx a tempo — e "a tempo"
         * inclui o dia em que o banco ficou lento. Aplicar duas vezes estenderia
         * o período em dobro e duplicaria a linha de faturamento do painel.
         */
        $this->enviaWebhook($evento)
            ->assertOk()
            ->assertJsonPath('message', 'Evento já processado.');

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertSame(
            1,
            SubscriptionPayment::query()->where('gateway_payment_id', 'pay_repetido')->count(),
        );
    }

    #[Test]
    public function fatura_recusada_marca_inadimplente_sem_cortar_o_acesso(): void
    {
        $assinatura = $this->assinatura();

        $this->enviaWebhook([
            'id' => 'evt_falha',
            'type' => GatewayEventType::PaymentFailed->value,
            'data' => ['subscription_id' => $assinatura->gateway_subscription_id],
        ])->assertOk();

        $recarregada = $this->empresa->fresh();

        /*
         * Cartão recusado é quase sempre limite estourado ou validade vencida,
         * não desistência. Quem corta o acesso é o fim do período já pago — a
         * régua de cobrança acontece sozinha, sem punir quem trocou de banco.
         */
        $this->assertSame(PlanStatus::PastDue, $recarregada->plan_status);
        $this->assertTrue($recarregada->acessoLiberado());
    }

    #[Test]
    public function webhook_de_evento_desconhecido_responde_200(): void
    {
        /*
         * Devolver erro faria o gateway reenviar em backoff por dias — e alguns
         * provedores desativam o endpoint depois de tantas falhas seguidas, o
         * que derrubaria junto os eventos que importam.
         */
        $this->enviaWebhook([
            'id' => 'evt_qualquer',
            'type' => 'invoice.draft.created',
            'data' => [],
        ])->assertOk();

        $this->assertDatabaseCount('webhook_events', 0);
    }

    #[Test]
    public function webhook_de_estorno_reconcilia_o_cancelamento(): void
    {
        $assinatura = $this->assinatura();
        $pagamento = SubscriptionPayment::query()->sole();

        /*
         * O caminho de reconciliação: se o estorno saiu no gateway mas a
         * gravação falhou aqui, este evento fecha a lacuna. Por isso ele não
         * pode presumir em que estado o pagamento está.
         */
        $this->enviaWebhook([
            'id' => 'evt_estorno',
            'type' => GatewayEventType::PaymentRefunded->value,
            'data' => [
                'subscription_id' => $assinatura->gateway_subscription_id,
                'payment_id' => $pagamento->gateway_payment_id,
                'amount' => $pagamento->amount,
            ],
        ])->assertOk();

        $this->assertSame(SubscriptionPaymentStatus::Refunded, $pagamento->fresh()->status);
        $this->assertSame(PlanStatus::Canceled, $this->empresa->fresh()->plan_status);
        $this->assertFalse($this->empresa->fresh()->acessoLiberado());
    }

    /* ── A fronteira com o livro caixa do assinante ────────────────────── */

    #[Test]
    public function o_pagamento_da_mensalidade_nao_entra_no_caixa_do_assinante(): void
    {
        $assinatura = $this->assinatura();

        $this->enviaWebhook([
            'id' => 'evt_mensalidade',
            'type' => GatewayEventType::PaymentSucceeded->value,
            'data' => [
                'subscription_id' => $assinatura->gateway_subscription_id,
                'payment_id' => 'pay_mensalidade',
                'amount' => 79.90,
            ],
        ])->assertOk();

        /*
         * São dois caixas de duas empresas diferentes. `transactions` é o livro
         * do ASSINANTE — as caixas que ele vende. Lançar a mensalidade lá
         * inflaria o faturamento do painel que ele usa para decidir preço, e
         * faria a régua dos 7 dias do CDC contar a partir da venda de uma caixa
         * qualquer.
         */
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('installments', 0);

        $this->actingAs($this->admin)
            ->getJson('/api/financial/dashboard')
            ->assertOk()
            ->assertJsonPath('data.revenue.realized', 0);
    }
}

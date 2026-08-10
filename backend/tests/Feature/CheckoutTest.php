<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GatewayEventType;
use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Enums\UserRole;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Contratação de plano e reconciliação de webhooks.
 *
 * O checkout fecha o único trecho do fluxo de cobrança que faltava: até agora
 * havia cancelamento e ativação por webhook, mas nada que iniciasse o pagamento
 * — a assinatura só podia nascer de um evento que ninguém provocava.
 */
class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->gratuito()->create();

        $this->admin = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::Admin,
        ]);
    }

    /* ── Abrir a sessão de pagamento ───────────────────────────────────── */

    #[Test]
    public function o_admin_abre_a_sessao_de_pagamento_e_recebe_a_url(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/subscriptions/checkout', ['plan_type' => 'basic'])
            ->assertOk()
            ->assertJsonPath('data.url', fn ($url) => is_string($url) && str_contains($url, 'plano=basic'))
            ->assertJsonPath('data.gateway_subscription_id', fn ($id) => is_string($id));
    }

    #[Test]
    public function o_checkout_nao_cria_assinatura_nenhuma(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/subscriptions/checkout', ['plan_type' => 'pro'])
            ->assertOk();

        /*
         * Quem cria é o webhook, depois que o dinheiro foi confirmado. Gravar
         * aqui produziria assinaturas "ativas" para todo mundo que clicou em
         * assinar e desistiu na tela do cartão — e o MRR do painel de plataforma
         * passaria a contar receita que não existe.
         */
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertSame(PlanType::Free, $this->empresa->fresh()->plan_type);
    }

    #[Test]
    public function o_e_mail_precisa_estar_confirmado_para_assinar(): void
    {
        $naoVerificado = User::factory()->unverified()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::Admin,
        ]);

        /*
         * Recibo, aviso de cobrança e link de segunda via vão todos por e-mail.
         * Cobrar de quem não se consegue alcançar produz, na melhor das
         * hipóteses, um estorno; na pior, uma contestação de cartão.
         */
        $this->actingAs($naoVerificado)
            ->postJson('/api/subscriptions/checkout', ['plan_type' => 'basic'])
            ->assertForbidden()
            ->assertJsonPath('error', 'email_nao_verificado');
    }

    #[Test]
    public function usuario_comum_nao_contrata_plano(): void
    {
        $comum = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::User,
        ]);

        $this->actingAs($comum)
            ->postJson('/api/subscriptions/checkout', ['plan_type' => 'basic'])
            ->assertForbidden();
    }

    #[Test]
    public function o_plano_gratuito_nao_passa_pelo_checkout(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/subscriptions/checkout', ['plan_type' => 'free'])
            ->assertUnprocessable();
    }

    #[Test]
    public function plano_inexistente_e_recusado(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/subscriptions/checkout', ['plan_type' => 'enterprise'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_type');
    }

    /* ── O ciclo completo ──────────────────────────────────────────────── */

    #[Test]
    public function simular_o_pagamento_ativa_a_assinatura_e_libera_a_cota(): void
    {
        $this->artisan('billing:simular-pagamento', [
            'tenant' => $this->empresa->id,
            'plano' => 'pro',
        ])->assertSuccessful();

        $recarregada = $this->empresa->fresh();

        $this->assertSame(PlanType::Pro, $recarregada->plan_type);
        $this->assertSame(PlanStatus::Active, $recarregada->plan_status);
        $this->assertTrue($recarregada->acessoLiberado());

        // Assinatura e pagamento gravados pelo mesmo SubscriptionManager que o
        // webhook real usa — não por escrita direta em tabela.
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('subscription_payments', 1);

        $this->actingAs($this->admin)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.plano.tipo', 'pro')
            ->assertJsonPath('data.cotas.materials.limite', null);
    }

    #[Test]
    public function a_simulacao_recusa_plano_gratuito_e_empresa_inexistente(): void
    {
        $this->artisan('billing:simular-pagamento', [
            'tenant' => $this->empresa->id,
            'plano' => 'free',
        ])->assertFailed();

        $this->artisan('billing:simular-pagamento', ['tenant' => 999999, 'plano' => 'pro'])
            ->assertFailed();
    }

    /* ── Reconciliação ─────────────────────────────────────────────────── */

    #[Test]
    public function a_reconciliacao_aplica_um_evento_que_ficou_para_tras(): void
    {
        $assinatura = Subscription::factory()->create(['tenant_id' => $this->empresa->id]);

        /*
         * Simula o pior caso que o BillingWebhookController prevê: o evento
         * chegou, foi gravado e a aplicação falhou. O dinheiro entrou no cartão
         * do cliente e não entrou no sistema.
         */
        $registro = WebhookEvent::create([
            'gateway' => 'fake',
            'external_id' => 'evt_preso',
            'type' => GatewayEventType::PaymentSucceeded->value,
            'payload' => [
                'id' => 'evt_preso',
                'type' => GatewayEventType::PaymentSucceeded->value,
                'data' => [
                    'subscription_id' => $assinatura->gateway_subscription_id,
                    'payment_id' => 'pay_preso',
                    'amount' => 79.90,
                ],
            ],
            'processed_at' => null,
            'error' => 'falha simulada',
        ]);

        $registro->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('billing:reconciliar')->assertSuccessful();

        $this->assertNotNull($registro->fresh()->processed_at);
        $this->assertNull($registro->fresh()->error);
        $this->assertDatabaseHas('subscription_payments', ['gateway_payment_id' => 'pay_preso']);
    }

    #[Test]
    public function a_reconciliacao_respeita_a_carencia(): void
    {
        $assinatura = Subscription::factory()->create(['tenant_id' => $this->empresa->id]);

        WebhookEvent::create([
            'gateway' => 'fake',
            'external_id' => 'evt_recente',
            'type' => GatewayEventType::PaymentSucceeded->value,
            'payload' => [
                'id' => 'evt_recente',
                'type' => GatewayEventType::PaymentSucceeded->value,
                'data' => [
                    'subscription_id' => $assinatura->gateway_subscription_id,
                    'payment_id' => 'pay_recente',
                ],
            ],
        ]);

        /*
         * Todo gateway reenvia o que não recebeu 2xx. Atacar um evento
         * recém-falhado faria o comando disputar o mesmo registro com o reenvio
         * do provedor.
         */
        $this->artisan('billing:reconciliar')->assertSuccessful();

        $this->assertDatabaseMissing('subscription_payments', ['gateway_payment_id' => 'pay_recente']);
    }

    #[Test]
    public function payload_que_o_driver_nao_le_mais_sai_da_fila_com_o_motivo_escrito(): void
    {
        $registro = WebhookEvent::create([
            'gateway' => 'fake',
            'external_id' => 'evt_formato_antigo',
            'type' => 'formato.antigo',
            'payload' => ['formato' => 'de outro provedor'],
        ]);

        $registro->forceFill(['created_at' => now()->subHour()])->save();

        // Sem isto, um evento em formato descontinuado ficaria eternamente na
        // fila fazendo o comando falhar todo hora.
        $this->artisan('billing:reconciliar')->assertSuccessful();

        $atualizado = $registro->fresh();

        $this->assertNotNull($atualizado->processed_at);
        $this->assertStringContainsString('não interpretável', (string) $atualizado->error);
    }
}

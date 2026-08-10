<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Material;
use App\Models\Quote;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O painel de quem OPERA o SaaS.
 *
 * O primeiro teste deste arquivo é o mais importante de toda a fase. O documento
 * da Fase 6 pedia o painel em `/api/admin/dashboard` — e aquele prefixo é
 * guardado por EnsureUserIsAdmin, que passa para o dono de QUALQUER empresa
 * assinante, porque a Fase 1 redefiniu "admin" como dono de uma empresa.
 * Implementar como o documento pedia entregaria a cada cliente o faturamento da
 * plataforma e a lista completa dos concorrentes dele.
 */
class PlatformPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $plataforma;

    private Tenant $alfa;

    private Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();

        // O que distingue o operador do SaaS não é o papel, é o tenant_id nulo.
        $this->plataforma = User::factory()->create([
            'tenant_id' => null,
            'role' => UserRole::Admin,
        ]);

        $this->alfa = Tenant::factory()->noPlano(PlanType::Basic)->create([
            'name' => 'Cartonagem Alfa',
            'state' => 'SP',
        ]);

        $this->beta = Tenant::factory()->noPlano(PlanType::Pro)->create([
            'name' => 'Cartonagem Beta',
            'state' => 'MG',
        ]);
    }

    /* ── A barreira ────────────────────────────────────────────────────── */

    #[Test]
    public function o_admin_de_uma_empresa_nao_alcanca_o_painel_de_plataforma(): void
    {
        $donoDaAlfa = User::factory()->create([
            'tenant_id' => $this->alfa->id,
            'role' => UserRole::Admin,
        ]);

        /*
         * 404 e não 403: um 403 confirmaria que a rota existe, e a existência de
         * um painel de plataforma é exatamente o que não interessa anunciar para
         * um assinante que resolveu adivinhar caminhos.
         */
        $this->actingAs($donoDaAlfa)->getJson('/api/platform/dashboard')->assertNotFound();
        $this->actingAs($donoDaAlfa)->getJson('/api/platform/tenants')->assertNotFound();
        $this->actingAs($donoDaAlfa)->getJson('/api/platform/users')->assertNotFound();
        $this->actingAs($donoDaAlfa)
            ->patchJson("/api/platform/tenants/{$this->beta->id}/plan", [
                'plan_type' => 'free',
                'motivo' => 'sabotagem',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function usuario_comum_e_visitante_nao_alcancam_o_painel(): void
    {
        $comum = User::factory()->create(['tenant_id' => $this->alfa->id, 'role' => UserRole::User]);

        $this->getJson('/api/platform/dashboard')->assertUnauthorized();
        $this->actingAs($comum)->getJson('/api/platform/dashboard')->assertNotFound();
    }

    #[Test]
    public function admin_de_plataforma_desativado_perde_o_acesso(): void
    {
        $this->plataforma->forceFill(['is_active' => false])->save();

        $this->actingAs($this->plataforma)->getJson('/api/platform/dashboard')->assertNotFound();
    }

    /* ── Os números ────────────────────────────────────────────────────── */

    #[Test]
    public function o_painel_conta_os_registros_de_todas_as_empresas(): void
    {
        Material::factory()->count(3)->create(['tenant_id' => $this->alfa->id]);
        $materiaisBeta = Material::factory()->count(2)->create(['tenant_id' => $this->beta->id]);
        Client::factory()->count(4)->create(['tenant_id' => $this->alfa->id]);

        // Material explícito: sem ele a factory de orçamento criaria um material
        // por orçamento, e o número sob teste deixaria de ser o que o teste diz.
        Quote::factory()->count(2)->create([
            'tenant_id' => $this->beta->id,
            'material_id' => $materiaisBeta->first()->id,
        ]);

        /*
         * A soma tem que atravessar empresas — é o custo de banco da plataforma
         * inteira. Se o TenantScope filtrasse aqui, o painel mostraria os
         * números de uma empresa só, sem erro e sem nada na tela denunciando.
         */
        $this->actingAs($this->plataforma)
            ->getJson('/api/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('data.consumo.materiais', 5)
            ->assertJsonPath('data.consumo.clientes', 4)
            ->assertJsonPath('data.consumo.orcamentos', 2);
    }

    #[Test]
    public function o_painel_separa_faturamento_realizado_de_receita_recorrente(): void
    {
        SubscriptionPayment::factory()->create([
            'tenant_id' => $this->alfa->id,
            'amount' => 79.90,
            'paid_at' => now(),
        ]);

        $resposta = $this->actingAs($this->plataforma)
            ->getJson('/api/platform/dashboard')
            ->assertOk();

        /*
         * Dois números com nomes diferentes de propósito. `bruto_mes` é extrato
         * — o que entrou. `mrr` é promessa — o que entra se ninguém cancelar.
         * Um só número misturando os dois é relatório de vendas fingindo ser
         * saldo bancário.
         */
        $resposta->assertJsonPath('data.faturamento.bruto_mes', 79.9);

        $esperado = PlanType::Basic->monthlyPrice() + PlanType::Pro->monthlyPrice();
        $resposta->assertJsonPath('data.faturamento.mrr', $esperado);
    }

    #[Test]
    public function o_estorno_sai_do_faturamento_do_mes(): void
    {
        SubscriptionPayment::factory()->create([
            'tenant_id' => $this->alfa->id,
            'amount' => 79.90,
            'paid_at' => now(),
        ]);

        SubscriptionPayment::factory()->create([
            'tenant_id' => $this->beta->id,
            'amount' => 149.90,
            'status' => SubscriptionPaymentStatus::Refunded,
            'paid_at' => now(),
            'refunded_at' => now(),
            'refunded_amount' => 149.90,
        ]);

        /*
         * Lê como extrato: os R$ 229,80 entraram, R$ 149,90 voltaram, sobram
         * R$ 79,90. O erro que este teste trava é o estorno ser contado duas
         * vezes — some da entrada E aparece na saída —, o que transformaria uma
         * venda de R$ 79,90 num prejuízo de R$ 70,00 que nunca existiu.
         */
        $this->actingAs($this->plataforma)
            ->getJson('/api/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('data.faturamento.bruto_mes', 229.8)
            ->assertJsonPath('data.faturamento.estornado_mes', 149.9)
            ->assertJsonPath('data.faturamento.liquido_mes', 79.9);
    }

    #[Test]
    public function o_painel_agrupa_as_empresas_por_uf(): void
    {
        Tenant::factory()->count(2)->create(['state' => 'SP']);
        Tenant::factory()->create(['state' => null]);

        $demografia = collect(
            $this->actingAs($this->plataforma)
                ->getJson('/api/platform/dashboard')
                ->assertOk()
                ->json('data.demografia')
        )->keyBy('uf');

        $this->assertSame(3, $demografia['SP']['total']);
        $this->assertSame(1, $demografia['MG']['total']);

        /*
         * Empresa sem UF vira `nao_informado` em vez de sumir: um vazio grande
         * demais é sinal de cadastro incompleto, e escondê-lo faria o mapa
         * parecer mais preciso do que é.
         */
        $this->assertSame(1, $demografia['nao_informado']['total']);
    }

    /* ── Gestão ────────────────────────────────────────────────────────── */

    #[Test]
    public function a_plataforma_promove_uma_empresa_manualmente(): void
    {
        $this->actingAs($this->plataforma)
            ->patchJson("/api/platform/tenants/{$this->alfa->id}/plan", [
                'plan_type' => 'pro',
                'max_quotes' => 999,
                'motivo' => 'Parceria de lançamento',
            ])
            ->assertOk();

        $recarregada = $this->alfa->fresh();

        $this->assertSame(PlanType::Pro, $recarregada->plan_type);
        $this->assertSame(999, $recarregada->max_quotes);
    }

    #[Test]
    public function a_alteracao_manual_de_plano_exige_motivo(): void
    {
        // O texto não vira coluna: ele é auditado pelo RegistraAcesso. Exigi-lo
        // obriga quem promove a escrever por quê.
        $this->actingAs($this->plataforma)
            ->patchJson("/api/platform/tenants/{$this->alfa->id}/plan", ['plan_type' => 'pro'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motivo');
    }

    #[Test]
    public function suspender_uma_empresa_nao_mexe_na_situacao_do_plano(): void
    {
        $this->actingAs($this->plataforma)
            ->patchJson("/api/platform/tenants/{$this->alfa->id}/suspend", [
                'ativo' => false,
                'motivo' => 'Uso indevido',
            ])
            ->assertOk();

        $recarregada = $this->alfa->fresh();

        $this->assertFalse($recarregada->is_active);
        $this->assertFalse($recarregada->acessoLiberado());

        /*
         * São dois eixos: is_active é decisão administrativa, plan_status é
         * consequência de pagamento. Se fossem o mesmo campo, o webhook de
         * fatura paga reabriria a conta de quem foi suspenso por abuso.
         */
        $this->assertSame(PlanStatus::Active, $recarregada->plan_status);
    }

    #[Test]
    public function a_plataforma_encerra_as_sessoes_de_um_usuario(): void
    {
        $usuario = User::factory()->create(['tenant_id' => $this->alfa->id]);
        $usuario->createToken('celular');
        $usuario->createToken('notebook');

        $this->actingAs($this->plataforma)
            ->postJson("/api/platform/users/{$usuario->id}/force-logout")
            ->assertOk();

        $this->assertSame(0, $usuario->tokens()->count());
    }

    #[Test]
    public function a_plataforma_manda_o_link_de_redefinicao_mas_nao_define_a_senha(): void
    {
        Notification::fake();

        $usuario = User::factory()->create(['tenant_id' => $this->alfa->id]);
        $senhaAntes = $usuario->password;
        $usuario->createToken('web');

        $this->actingAs($this->plataforma)
            ->postJson("/api/platform/users/{$usuario->id}/password-reset")
            ->assertOk();

        /*
         * A fronteira do arquivo. Quem define a senha de alguém consegue entrar
         * como essa pessoa, e a partir daí nenhum registro distingue o titular
         * do operador — "quem aprovou este orçamento" deixa de ter resposta
         * confiável.
         */
        $this->assertSame($senhaAntes, $usuario->fresh()->password);

        // Sessões revogadas junto: se a redefinição foi pedida porque a conta
        // pode estar comprometida, deixar o token do invasor vivo anula o
        // propósito.
        $this->assertSame(0, $usuario->tokens()->count());

        Notification::assertSentTo($usuario, ResetPassword::class);
    }

    #[Test]
    public function desativar_um_usuario_derruba_as_sessoes_dele(): void
    {
        $usuario = User::factory()->create(['tenant_id' => $this->alfa->id]);
        $usuario->createToken('web');

        $this->actingAs($this->plataforma)
            ->patchJson("/api/platform/users/{$usuario->id}/active", [
                'ativo' => false,
                'motivo' => 'A pedido da empresa',
            ])
            ->assertOk();

        // Desativar sem revogar deixaria o token atual funcionando até expirar:
        // o middleware de admin checa is_active, mas as rotas de usuário não.
        $this->assertFalse($usuario->fresh()->is_active);
        $this->assertSame(0, $usuario->tokens()->count());
    }

    #[Test]
    public function a_listagem_de_empresas_mostra_o_consumo_de_cada_uma(): void
    {
        Material::factory()->count(3)->create(['tenant_id' => $this->alfa->id]);

        $empresas = collect(
            $this->actingAs($this->plataforma)
                ->getJson('/api/platform/tenants')
                ->assertOk()
                ->json('data')
        )->keyBy('id');

        $this->assertSame(3, $empresas[$this->alfa->id]['cotas']['materials']['usado']);

        // Pro é ilimitado: null significa "sem teto", e o frontend precisa
        // distinguir isso de "teto zero".
        $this->assertNull($empresas[$this->beta->id]['cotas']['materials']['limite']);
    }
}

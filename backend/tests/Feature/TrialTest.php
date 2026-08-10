<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Enums\TenantQuota;
use App\Enums\UserRole;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\QuotaGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O fim do período de teste.
 *
 * A regra de produto que estes testes travam cabe numa frase: o teste vencido
 * REBAIXA, nunca bloqueia. Quem passou três dias avaliando e não assinou não é
 * inadimplente — é alguém que ainda não se convenceu, e os materiais e custos
 * que essa pessoa cadastrou continuam sendo dela. Cortar o acesso transformaria
 * uma avaliação morna numa reclamação.
 *
 * O segundo tema é a janela entre a virada e o cron: `plan_type` ainda diz "Pro"
 * enquanto a verdade já é "gratuito", e ler a coluna nessa hora daria cotas
 * ilimitadas de graça a cada empresa que testou o produto.
 */
class TrialTest extends TestCase
{
    use RefreshDatabase;

    private function emTeste(int $diasRestantes): Tenant
    {
        return Tenant::factory()->create([
            'plan_type' => PlanType::Pro,
            'plan_status' => PlanStatus::Trialing,
            'trial_ends_at' => now()->addDays($diasRestantes),
            'subscription_ends_at' => null,
        ]);
    }

    /* ── O plano vigente ───────────────────────────────────────────────── */

    #[Test]
    public function durante_o_teste_vale_o_plano_profissional(): void
    {
        $tenant = $this->emTeste(diasRestantes: 2);

        $this->assertSame(PlanType::Pro, $tenant->planoVigente());
        $this->assertNull(app(QuotaGuard::class)->limite($tenant, TenantQuota::Materials));
    }

    #[Test]
    public function o_teste_vencido_rebaixa_a_cota_antes_de_o_cron_passar(): void
    {
        $tenant = $this->emTeste(diasRestantes: -1);

        /*
         * A coluna ainda diz Pro — o cron roda às 04:00. Se a cota lesse a
         * coluna, toda empresa cujo teste venceu ganharia até um dia de plano
         * ilimitado de graça, todo dia, para sempre.
         */
        $this->assertSame(PlanType::Pro, $tenant->plan_type);
        $this->assertSame(PlanType::Free, $tenant->planoVigente());

        $this->assertSame(
            PlanType::Free->maxMaterials(),
            app(QuotaGuard::class)->limite($tenant, TenantQuota::Materials),
        );
    }

    #[Test]
    public function empresa_sem_teste_nenhum_nao_e_afetada(): void
    {
        // trial_ends_at nulo: quem nunca entrou em teste segue o plano da coluna,
        // mesmo com o status default `trialing` que o banco aplica.
        $tenant = Tenant::factory()->create([
            'plan_type' => PlanType::Basic,
            'plan_status' => PlanStatus::Trialing,
            'trial_ends_at' => null,
        ]);

        $this->assertSame(PlanType::Basic, $tenant->planoVigente());
    }

    /* ── Rebaixar, não bloquear ────────────────────────────────────────── */

    #[Test]
    public function o_teste_vencido_nao_bloqueia_escrita(): void
    {
        $tenant = $this->emTeste(diasRestantes: -1);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::Admin]);

        /*
         * A diferença entre teste vencido e assinatura vencida. O primeiro
         * rebaixa; só o segundo bloqueia, e ele se identifica por
         * `subscription_ends_at`, que o provisionamento deixa nulo de propósito.
         */
        $this->actingAs($admin)
            ->postJson('/api/clients', ['name' => 'Cliente novo'])
            ->assertCreated();

        $this->assertTrue($tenant->fresh()->acessoLiberado());
    }

    #[Test]
    public function depois_do_teste_a_empresa_esbarra_na_cota_do_gratuito(): void
    {
        $tenant = $this->emTeste(diasRestantes: -1);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::Admin]);

        Material::factory()
            ->count((int) PlanType::Free->maxMaterials())
            ->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)
            ->postJson('/api/admin/materials', [
                'name' => 'Além do teto do gratuito',
                'type' => 'cardboard',
                'cost_unit' => 'm2',
                'cost_per_unit' => 2.75,
                'thickness_mm' => 1.9,
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'limite_atingido');
    }

    /* ── O comando ─────────────────────────────────────────────────────── */

    #[Test]
    public function o_comando_rebaixa_os_testes_vencidos(): void
    {
        $vencido = $this->emTeste(diasRestantes: -1);
        $vigente = $this->emTeste(diasRestantes: 2);

        $this->artisan('billing:encerrar-testes')->assertSuccessful();

        $this->assertSame(PlanType::Free, $vencido->fresh()->plan_type);
        $this->assertSame(PlanStatus::Active, $vencido->fresh()->plan_status);

        $this->assertSame(PlanType::Pro, $vigente->fresh()->plan_type);
        $this->assertSame(PlanStatus::Trialing, $vigente->fresh()->plan_status);
    }

    #[Test]
    public function o_comando_marca_como_ativa_e_nao_como_cancelada(): void
    {
        $tenant = $this->emTeste(diasRestantes: -1);

        $this->artisan('billing:encerrar-testes')->assertSuccessful();

        /*
         * "Nunca assinou" e "assinou e desistiu" são coisas diferentes para quem
         * lê o painel decidindo onde investir. Marcar o fim do teste como
         * cancelamento misturaria as duas na mesma coluna.
         */
        $this->assertSame(PlanStatus::Active, $tenant->fresh()->plan_status);
    }

    #[Test]
    public function o_comando_nao_toca_a_data_de_acesso(): void
    {
        $tenant = $this->emTeste(diasRestantes: -1);

        $this->artisan('billing:encerrar-testes')->assertSuccessful();

        // Preencher subscription_ends_at aqui converteria o rebaixamento em
        // bloqueio, pelo EnsureSubscriptionIsActive.
        $this->assertNull($tenant->fresh()->subscription_ends_at);
        $this->assertTrue($tenant->fresh()->acessoLiberado());
    }

    #[Test]
    public function rodar_o_comando_duas_vezes_nao_reescreve_as_mesmas_linhas(): void
    {
        $tenant = $this->emTeste(diasRestantes: -1);

        $this->artisan('billing:encerrar-testes')->assertSuccessful();
        $tocadoEm = $tenant->fresh()->updated_at;

        $this->travel(1)->hour();
        $this->artisan('billing:encerrar-testes')->assertSuccessful();

        // Reescrever todo dia sujaria updated_at e esconderia, num histórico de
        // alterações, qual foi a mudança que de fato aconteceu.
        $this->assertTrue($tocadoEm->equalTo($tenant->fresh()->updated_at));
    }

    #[Test]
    public function a_simulacao_nao_grava(): void
    {
        $tenant = $this->emTeste(diasRestantes: -1);

        $this->artisan('billing:encerrar-testes', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(PlanType::Pro, $tenant->fresh()->plan_type);
    }

    /* ── O que a tela lê ───────────────────────────────────────────────── */

    #[Test]
    public function o_endpoint_de_assinatura_mostra_o_plano_vigente_e_o_contratado(): void
    {
        $tenant = $this->emTeste(diasRestantes: -1);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::Admin]);

        // Mostrar o contratado como se fosse o vigente faria a tela prometer um
        // limite que o servidor recusa.
        $this->actingAs($admin)
            ->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.plano.tipo', 'free')
            ->assertJsonPath('data.plano.contratado', 'pro')
            ->assertJsonPath('data.em_teste', false);
    }

    #[Test]
    public function o_me_devolve_plano_e_cotas_numa_chamada_so(): void
    {
        $tenant = $this->emTeste(diasRestantes: 2);
        $usuario = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($usuario)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $usuario->email)
            ->assertJsonPath('data.tenant.id', $tenant->id)
            ->assertJsonPath('data.plano.tipo', 'pro')
            ->assertJsonPath('data.cotas.materials.limite', null);
    }

    #[Test]
    public function o_me_do_admin_de_plataforma_nao_tem_empresa_nem_cota(): void
    {
        $plataforma = User::factory()->platformAdmin()->create();

        /*
         * O nulo aqui é informação, não ausência: é ele que o frontend lê para
         * escolher entre a interface do assinante e a de operação do SaaS.
         */
        $this->actingAs($plataforma)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.tenant', null)
            ->assertJsonPath('data.plano', null)
            ->assertJsonPath('data.cotas', null);
    }
}

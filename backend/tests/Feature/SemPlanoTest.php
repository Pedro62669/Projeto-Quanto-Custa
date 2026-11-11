<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantQuota;
use App\Enums\UserRole;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\QuotaGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A conta sem plano — o que substituiu o teste grátis e o plano gratuito.
 *
 * Este arquivo nasceu no lugar do TrialTest, e a regra que ele trava é o oposto
 * da que estava lá. Antes: o teste vencido REBAIXAVA para o gratuito e nunca
 * bloqueava. Agora não existe teste nem gratuito, e a conta recém-criada fica em
 * MODO DEMONSTRAÇÃO: lê tudo, escreve nada.
 *
 * A decisão é comercial, não técnica, e vale registrá-la: não há mais nada de
 * graça no produto. O que sobra para quem ainda não assinou é ver o sistema
 * funcionando — inclusive a calculadora, com uma caixa de exemplo precificada
 * pelo motor de verdade.
 *
 * Os três testes do fim do arquivo são os que mais importam: sem eles, a trava
 * viraria um beco sem saída, porque o caminho para pagar passa por rotas de
 * escrita.
 */
class SemPlanoTest extends TestCase
{
    use RefreshDatabase;

    /** Empresa recém-cadastrada: sem plano, sem situação, sem período pago. */
    private function semPlano(): Tenant
    {
        return Tenant::factory()->semPlano()->create();
    }

    private function admin(Tenant $tenant): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::Admin,
        ]);
    }

    /**
     * O mínimo para a demonstração ter o que calcular.
     *
     * No sistema real isto vem do ProvisionaEmpresa, que semeia custos e quatro
     * matérias-primas no cadastro. A factory de Tenant não passa por lá — cria a
     * empresa e mais nada — então aqui o estoque é montado à mão.
     */
    private function preparaParaCalcular(Tenant $tenant): void
    {
        CostSetting::factory()->create(['tenant_id' => $tenant->id]);

        Material::factory()->create([
            'tenant_id' => $tenant->id,
            'cost_per_unit' => 3.20,
            'default_waste_percent' => 10.0,
            'thickness_mm' => 1.5,
        ]);
    }

    /* ── O estado ──────────────────────────────────────────────────────── */

    #[Test]
    public function a_empresa_nasce_sem_plano_nenhum(): void
    {
        $tenant = $this->semPlano();

        $this->assertTrue($tenant->semPlano());
        $this->assertNull($tenant->plan_type);
        $this->assertNull($tenant->plan_status);
    }

    #[Test]
    public function sem_plano_nao_ha_acesso_de_escrita(): void
    {
        $this->assertFalse($this->semPlano()->acessoLiberado());
    }

    #[Test]
    public function a_cota_sem_plano_e_zero_e_nao_ilimitada(): void
    {
        $tenant = $this->semPlano();
        $cotas = app(QuotaGuard::class);

        /*
         * Null significa ILIMITADO no contrato de cotas. Devolvê-lo para quem
         * não tem plano liberaria cadastro infinito a toda conta nova — a
         * inversão de significado mais cara que o QuotaGuard poderia esconder.
         */
        foreach (TenantQuota::cases() as $quota) {
            $this->assertSame(0, $cotas->limite($tenant, $quota), $quota->value);
        }
    }

    #[Test]
    public function a_cortesia_manual_vence_a_ausencia_de_plano(): void
    {
        $tenant = $this->semPlano();
        $tenant->forceFill(['max_materials' => 5])->save();

        // A exceção concedida à empresa precisa funcionar justamente em quem
        // ainda não assinou — é o único momento em que conceder faz sentido.
        $this->assertSame(5, app(QuotaGuard::class)->limite($tenant, TenantQuota::Materials));
    }

    /* ── Lê tudo, escreve nada ─────────────────────────────────────────── */

    #[Test]
    public function sem_plano_a_escrita_e_recusada(): void
    {
        $tenant = $this->semPlano();

        $this->actingAs($this->admin($tenant))
            ->postJson('/api/clients', ['name' => 'Cliente novo'])
            ->assertForbidden()
            ->assertJsonPath('error', 'assinatura_necessaria');
    }

    #[Test]
    public function a_recusa_de_quem_nunca_assinou_nao_fala_em_fatura_vencida(): void
    {
        $tenant = $this->semPlano();

        $resposta = $this->actingAs($this->admin($tenant))
            ->postJson('/api/clients', ['name' => 'Cliente novo']);

        /*
         * Mandar quem nunca teve assinatura procurar uma fatura em atraso é
         * fabricar um chamado de suporte. O texto precisa dizer o que de fato
         * falta: escolher um plano.
         */
        $this->assertStringNotContainsString('vencida', (string) $resposta->json('message'));
        $this->assertStringContainsString('plano', (string) $resposta->json('message'));
    }

    #[Test]
    public function sem_plano_a_leitura_continua_liberada(): void
    {
        $tenant = $this->semPlano();
        $admin = $this->admin($tenant);

        // LGPD, art. 18: o titular acessa o que é dele. E, comercialmente, é a
        // demonstração inteira — sem leitura não há o que mostrar.
        $this->actingAs($admin)->getJson('/api/clients')->assertOk();
        $this->actingAs($admin)->getJson('/api/quotes')->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/materials')->assertOk();
    }

    /* ── A calculadora congelada ───────────────────────────────────────── */

    #[Test]
    public function a_simulacao_e_bloqueada_sem_plano(): void
    {
        $tenant = $this->semPlano();

        /*
         * Precificar a caixa do cliente É o produto. Liberar `simulate` seria
         * entregá-lo inteiro — bastaria chamar a API direto para nunca pagar.
         */
        $this->actingAs($this->admin($tenant))
            ->postJson('/api/quotes/simulate', [
                'material_id' => 1,
                'width_mm' => 200,
                'height_mm' => 100,
                'depth_mm' => 150,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_demonstracao_responde_sem_plano_e_traz_preco(): void
    {
        $tenant = $this->semPlano();
        $this->preparaParaCalcular($tenant);

        $resposta = $this->actingAs($this->admin($tenant))
            ->getJson('/api/quotes/demo')
            ->assertOk();

        // O preço é conta de verdade, feita pelo mesmo motor do assinante.
        $this->assertGreaterThan(0, $resposta->json('data.resultado.unit_price'));
        $this->assertSame(200, $resposta->json('data.specification.width_mm'));
    }

    #[Test]
    public function a_demonstracao_ignora_o_que_o_cliente_manda(): void
    {
        $tenant = $this->semPlano();
        $this->preparaParaCalcular($tenant);

        $admin = $this->admin($tenant);
        $original = $this->actingAs($admin)->getJson('/api/quotes/demo')->json('data');

        /*
         * "Valores congelados" precisa ser verdade no SERVIDOR, e não só na
         * tela. Uma query string com medidas dez vezes maiores não pode mover o
         * preço — senão a demonstração vira a calculadora completa com outro
         * nome.
         */
        $tentativa = $this->actingAs($admin)
            ->getJson('/api/quotes/demo?width_mm=2000&quantity=99999')
            ->json('data');

        $this->assertSame($original['specification'], $tentativa['specification']);
        $this->assertSame(
            $original['resultado']['unit_price'],
            $tentativa['resultado']['unit_price'],
        );
    }

    /* ── O caminho para pagar não pode estar trancado ──────────────────── */

    #[Test]
    public function sem_plano_o_checkout_continua_aberto(): void
    {
        $tenant = $this->semPlano();
        $admin = $this->admin($tenant);
        $admin->forceFill(['email_verified_at' => now()])->save();

        /*
         * O teste mais importante do arquivo. `checkout` é POST e a conta sem
         * plano cai no middleware desde o primeiro minuto: fechá-lo deixaria a
         * pessoa numa conta que NUNCA consegue pagar, porque o único jeito de
         * pagar estava atrás da trava de quem não pagou.
         */
        $this->actingAs($admin)
            ->postJson('/api/subscriptions/checkout', ['plan_type' => 'basic'])
            ->assertOk();
    }

    #[Test]
    public function sem_plano_o_reenvio_da_confirmacao_continua_aberto(): void
    {
        $tenant = $this->semPlano();

        // O checkout EXIGE e-mail confirmado. Bloquear o reenvio fecharia a
        // porta um passo antes da anterior.
        $this->actingAs($this->admin($tenant))
            ->postJson('/api/email/verification-notification')
            ->assertOk();
    }

    /* ── O que a tela lê ───────────────────────────────────────────────── */

    #[Test]
    public function o_endpoint_de_assinatura_diz_que_nao_ha_plano(): void
    {
        $tenant = $this->semPlano();

        $this->actingAs($this->admin($tenant))
            ->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.sem_plano', true)
            ->assertJsonPath('data.plano.tipo', null)
            ->assertJsonPath('data.acesso_liberado', false);
    }

    #[Test]
    public function o_me_devolve_plano_e_cotas_numa_chamada_so(): void
    {
        $tenant = Tenant::factory()->create();
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

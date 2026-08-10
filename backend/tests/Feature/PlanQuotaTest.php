<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Enums\TenantQuota;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\QuotaGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cotas de plano e bloqueio por assinatura vencida.
 *
 * A cota é o que transforma o plano em produto: sem ela, "gratuito" e
 * "profissional" são o mesmo software com preços diferentes. Cada teste aqui
 * descreve ou um jeito de a cota falhar em barrar, ou um jeito de ela barrar
 * demais — os dois erros custam dinheiro, em direções opostas.
 */
class PlanQuotaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $admin;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->gratuito()->create();

        $this->admin = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::Admin,
        ]);

        CostSetting::factory()->create(['tenant_id' => $this->empresa->id]);

        $this->material = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'cost_per_unit' => 3.20,
            'thickness_mm' => 0.0,
        ]);
    }

    /** @return array<string, mixed> */
    private function orcamento(array $overrides = []): array
    {
        return [
            'material_id' => $this->material->id,
            'box_model' => 'rsc',
            'width_mm' => 300,
            'height_mm' => 200,
            'depth_mm' => 150,
            'quantity' => 100,
            'waste_percent' => 10,
            'production_minutes_per_unit' => 2.5,
            'profit_margin_percent' => 30,
            'pricing_mode' => 'markup',
            'client_name' => 'Cliente',
            ...$overrides,
        ];
    }

    /* ── O teto ────────────────────────────────────────────────────────── */

    #[Test]
    public function o_cadastro_de_material_para_no_teto_do_plano(): void
    {
        $teto = (int) PlanType::Free->maxMaterials();

        // O setUp já criou um; completa até um a menos que o teto.
        Material::factory()->count($teto - 2)->create(['tenant_id' => $this->empresa->id]);

        $payload = [
            'name' => 'Papelão extra',
            'type' => 'cardboard',
            'cost_unit' => 'm2',
            'cost_per_unit' => 2.75,
            'thickness_mm' => 1.9,
        ];

        // O que fecha a cota ainda entra: o bloqueio é em "já atingiu", não em
        // "vai atingir" — cobrar antes do limite seria vender N e entregar N-1.
        $this->actingAs($this->admin)
            ->postJson('/api/admin/materials', $payload)
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/materials', ['name' => 'Papelão além do teto'] + $payload)
            ->assertForbidden()
            ->assertJsonPath('error', 'limite_atingido')
            ->assertJsonPath('quota.recurso', 'materials')
            ->assertJsonPath('quota.limite', $teto);
    }

    #[Test]
    public function estourar_a_cota_nao_impede_editar_nem_apagar(): void
    {
        Material::factory()
            ->count((int) PlanType::Free->maxMaterials())
            ->create(['tenant_id' => $this->empresa->id]);

        /*
         * A garantia que impede a cota de virar coerção: quem estourou precisa
         * poder arrumar a casa para caber de novo. Uma trava que bloqueia o
         * próprio conserto deixa o upgrade como única saída.
         */
        $this->actingAs($this->admin)
            ->putJson("/api/admin/materials/{$this->material->id}", [
                'name' => 'Nome corrigido',
                'cost_per_unit' => 4.10,
            ])
            ->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/materials/{$this->material->id}")
            ->assertOk();
    }

    #[Test]
    public function a_cota_de_orcamentos_e_mensal_e_nao_acumulada(): void
    {
        $teto = (int) PlanType::Free->maxQuotesPerMonth();

        /*
         * O ponto do desenho. Orçamento é o lastro da venda aprovada — a
         * transação do caixa aponta para ele e a ficha técnica sai dele. Um teto
         * ACUMULADO faria a empresa parar de vender ao completar um ano de uso,
         * e o único jeito de destravar seria apagar o próprio histórico
         * financeiro.
         */
        Quote::factory()->count($teto)->create([
            'tenant_id' => $this->empresa->id,
            'created_at' => now()->subMonth(),
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/quotes', $this->orcamento())
            ->assertCreated();
    }

    #[Test]
    public function orcamentos_do_mes_corrente_contam_para_a_cota(): void
    {
        Quote::factory()
            ->count((int) PlanType::Free->maxQuotesPerMonth())
            ->create(['tenant_id' => $this->empresa->id]);

        $this->actingAs($this->admin)
            ->postJson('/api/quotes', $this->orcamento())
            ->assertForbidden()
            ->assertJsonPath('error', 'limite_atingido')
            ->assertJsonPath('quota.mensal', true);
    }

    #[Test]
    public function apagar_orcamento_nao_libera_vaga_no_mes(): void
    {
        $orcamentos = Quote::factory()
            ->count((int) PlanType::Free->maxQuotesPerMonth())
            ->create(['tenant_id' => $this->empresa->id]);

        // Exclusão lógica: a linha continua no banco, e a cota existe para
        // conter justamente o volume gravado. Ignorar os apagados tornaria
        // "apagar e recriar" um jeito trivial de imprimir orçamentos infinitos.
        $orcamentos->first()->delete();

        $this->actingAs($this->admin)
            ->postJson('/api/quotes', $this->orcamento())
            ->assertForbidden();
    }

    #[Test]
    public function a_simulacao_nao_consome_cota(): void
    {
        Quote::factory()
            ->count((int) PlanType::Free->maxQuotesPerMonth())
            ->create(['tenant_id' => $this->empresa->id]);

        /*
         * A calculadora dispara em debounce a cada tecla. Se simular consumisse
         * cota, o plano gratuito acabaria antes do primeiro orçamento salvo.
         */
        $this->actingAs($this->admin)
            ->postJson('/api/quotes/simulate', $this->orcamento())
            ->assertOk();
    }

    #[Test]
    public function o_plano_profissional_nao_tem_teto(): void
    {
        $pro = Tenant::factory()->noPlano(PlanType::Pro)->create();
        $usuario = User::factory()->create(['tenant_id' => $pro->id, 'role' => UserRole::Admin]);

        Material::factory()->count(80)->create(['tenant_id' => $pro->id]);

        $this->actingAs($usuario)
            ->postJson('/api/admin/materials', [
                'name' => 'Material 81',
                'type' => 'cardboard',
                'cost_unit' => 'm2',
                'cost_per_unit' => 2.75,
                'thickness_mm' => 1.9,
            ])
            ->assertCreated();
    }

    /* ── Cortesia ──────────────────────────────────────────────────────── */

    #[Test]
    public function a_cortesia_manual_vence_o_padrao_do_plano(): void
    {
        $guarda = app(QuotaGuard::class);

        $this->assertSame(
            PlanType::Free->maxClients(),
            $guarda->limite($this->empresa, TenantQuota::Clients),
            'Sem cortesia, vale o número do plano.',
        );

        $this->empresa->forceFill(['max_clients' => 500])->save();

        $this->assertSame(
            500,
            $guarda->limite($this->empresa->fresh(), TenantQuota::Clients),
            'Com cortesia, vale a coluna da empresa.',
        );
    }

    #[Test]
    public function limpar_a_cortesia_devolve_a_empresa_ao_padrao_do_plano(): void
    {
        // O null da coluna significa "segue o plano", e não "ilimitado" — é a
        // diferença que permite subir o teto de um plano sem UPDATE em massa.
        $this->empresa->forceFill(['max_clients' => 500])->save();
        $this->empresa->forceFill(['max_clients' => null])->save();

        $this->assertSame(
            PlanType::Free->maxClients(),
            app(QuotaGuard::class)->limite($this->empresa->fresh(), TenantQuota::Clients),
        );
    }

    #[Test]
    public function clientes_inativos_continuam_ocupando_cota(): void
    {
        $teto = (int) PlanType::Free->maxClients();

        Client::factory()->count($teto)->create([
            'tenant_id' => $this->empresa->id,
            'is_active' => false,
        ]);

        /*
         * ClientController::destroy desativa em vez de apagar, porque o caixa
         * precisa da contraparte. Se inativo não contasse, a cota perderia o
         * efeito depois do primeiro ciclo de limpeza.
         */
        $this->actingAs($this->admin)
            ->postJson('/api/clients', ['name' => 'Mais um cliente'])
            ->assertForbidden();
    }

    /* ── O plano não vaza pelo formulário ──────────────────────────────── */

    #[Test]
    public function o_plano_nao_pode_ser_alterado_por_atribuicao_em_massa(): void
    {
        /*
         * Cenário de ataque: um update de perfil da empresa carregando
         * plan_type. Se o campo estivesse no $fillable, qualquer tela de
         * cadastro viraria um upgrade grátis.
         */
        $this->empresa->update([
            'name' => 'Nome novo',
            'plan_type' => PlanType::Pro,
            'max_materials' => 9999,
        ]);

        $recarregada = $this->empresa->fresh();

        $this->assertSame('Nome novo', $recarregada->name);
        $this->assertSame(PlanType::Free, $recarregada->plan_type);
        $this->assertNull($recarregada->max_materials);
    }

    /* ── Assinatura vencida ────────────────────────────────────────────── */

    #[Test]
    public function assinatura_vencida_bloqueia_escrita(): void
    {
        $this->empresa->forceFill(['subscription_ends_at' => now()->subDay()])->save();

        $this->actingAs($this->admin)
            ->postJson('/api/quotes', $this->orcamento())
            ->assertForbidden()
            ->assertJsonPath('error', 'assinatura_expirada');

        $this->actingAs($this->admin)
            ->postJson('/api/clients', ['name' => 'Cliente'])
            ->assertForbidden()
            ->assertJsonPath('error', 'assinatura_expirada');
    }

    #[Test]
    public function assinatura_vencida_nao_bloqueia_leitura_nem_exportacao(): void
    {
        $orcamento = Quote::factory()->create(['tenant_id' => $this->empresa->id]);

        $this->empresa->forceFill(['subscription_ends_at' => now()->subDay()])->save();

        /*
         * A decisão de fundo do EnsureSubscriptionIsActive. Reter dado de
         * titular como alavanca de cobrança esbarra no direito de acesso da
         * LGPD (art. 18, I e II) — e é a via mais curta para virar reclamação
         * pública. Cobra-se tirando o que é novo, não o que já é dele.
         */
        $this->actingAs($this->admin)->getJson('/api/quotes')->assertOk();
        $this->actingAs($this->admin)->getJson("/api/quotes/{$orcamento->id}")->assertOk();
        $this->actingAs($this->admin)->getJson('/api/materials')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/financial/dashboard')->assertOk();
    }

    #[Test]
    public function assinatura_vencida_nao_impede_cancelar_nem_excluir_a_conta(): void
    {
        $this->empresa->forceFill(['subscription_ends_at' => now()->subDay()])->save();

        /*
         * Os caminhos de SAÍDA continuam abertos. Bloqueá-los prenderia a pessoa
         * numa assinatura que ela não consegue nem encerrar — e a exclusão de
         * conta é direito ao esquecimento, que não depende de estar em dia.
         *
         * 422 e não 403: chegou ao controller (não foi barrado pelo middleware)
         * e lá descobriu que não há assinatura ativa para cancelar.
         */
        $this->actingAs($this->admin)
            ->postJson('/api/subscriptions/cancel', ['password' => 'password'])
            ->assertUnprocessable();
    }

    #[Test]
    public function o_admin_de_plataforma_nao_esbarra_em_cota(): void
    {
        // Sem empresa não há plano a consultar; o operador do SaaS não pode
        // ficar preso a um teto que não é dele.
        $plataforma = User::factory()->create(['tenant_id' => null, 'role' => UserRole::Admin]);

        Material::factory()->count(50)->create(['tenant_id' => $this->empresa->id]);

        $this->actingAs($plataforma)->getJson('/api/admin/materials')->assertOk();
    }

    /* ── O resumo que o frontend consome ───────────────────────────────── */

    #[Test]
    public function o_endpoint_de_assinatura_devolve_o_consumo_das_cotas(): void
    {
        Material::factory()->count(3)->create(['tenant_id' => $this->empresa->id]);

        $this->actingAs($this->admin)
            ->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.plano.tipo', 'free')
            ->assertJsonPath('data.cotas.materials.limite', PlanType::Free->maxMaterials())
            // 3 criados aqui + 1 do setUp.
            ->assertJsonPath('data.cotas.materials.usado', 4)
            ->assertJsonPath('data.cotas.quotes.mensal', true);
    }
}

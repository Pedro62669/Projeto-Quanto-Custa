<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Isolamento entre empresas — a garantia central do multi-inquilino.
 *
 * Cada teste aqui descreve um caminho pelo qual o dado de uma empresa poderia
 * chegar à outra: leitura de lista, leitura direta por id (IDOR), escrita
 * cruzada por payload, cache e sequência de referências. A trait é uma
 * afirmação de segurança; sem estes testes ela seria só um comentário.
 *
 * Os testes montam DUAS empresas explicitamente, ignorando o default
 * compartilhado das factories — é justamente o cruzamento que está sob teste.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $alfa;

    private Tenant $beta;

    private User $usuarioAlfa;

    private User $usuarioBeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = Tenant::factory()->create(['name' => 'Cartonagem Alfa']);
        $this->beta = Tenant::factory()->create(['name' => 'Cartonagem Beta']);

        $this->usuarioAlfa = User::factory()->create(['tenant_id' => $this->alfa->id]);
        $this->usuarioBeta = User::factory()->create(['tenant_id' => $this->beta->id]);
    }

    /* ── Leitura ───────────────────────────────────────────────────────── */

    #[Test]
    public function a_listagem_de_materiais_mostra_so_os_da_propria_empresa(): void
    {
        Material::factory()->create(['tenant_id' => $this->alfa->id, 'name' => 'Papelão da Alfa']);
        Material::factory()->create(['tenant_id' => $this->beta->id, 'name' => 'Papelão da Beta']);

        $response = $this->actingAs($this->usuarioAlfa)->getJson('/api/materials');

        $response->assertOk();

        $nomes = collect($response->json('data'))->pluck('name');

        $this->assertContains('Papelão da Alfa', $nomes);
        $this->assertNotContains('Papelão da Beta', $nomes);
    }

    #[Test]
    public function o_escopo_se_aplica_tambem_a_consulta_direta_por_id(): void
    {
        $materialDaBeta = Material::factory()->create(['tenant_id' => $this->beta->id]);

        $this->actingAs($this->usuarioAlfa);

        /*
         * find() pelo id exato, que é o gesto do ataque de IDOR: o atacante já
         * sabe o número e não depende de listagem nenhuma. O escopo precisa
         * responder null, e não o registro.
         */
        $this->assertNull(Material::find($materialDaBeta->id));
    }

    #[Test]
    public function um_usuario_nao_abre_o_orcamento_de_outra_empresa(): void
    {
        $orcamentoDaBeta = $this->criarOrcamento($this->beta, $this->usuarioBeta);

        $this->actingAs($this->usuarioAlfa)
            ->getJson("/api/quotes/{$orcamentoDaBeta->id}")
            ->assertNotFound();
    }

    /* ── Escrita ───────────────────────────────────────────────────────── */

    #[Test]
    public function o_tenant_id_enviado_no_payload_e_ignorado(): void
    {
        /*
         * A metade silenciosa do problema: escrever no vizinho. O atacante não
         * veria o resultado (o escopo o esconderia de volta), mas o registro
         * estaria lá, contaminando a base de outra empresa.
         */
        $material = Material::create([
            'tenant_id' => $this->beta->id,
            'name' => 'Tentativa de invasão',
            'type' => 'cardboard',
            'cost_unit' => 'm2',
            'cost_per_unit' => 3.20,
        ]);

        $this->assertSame($this->beta->id, $material->tenant_id, 'sem usuário logado, o valor explícito vale');

        $this->actingAs($this->usuarioAlfa);

        $comUsuarioLogado = Material::create([
            'tenant_id' => $this->beta->id,
            'name' => 'Tentativa com sessão',
            'type' => 'cardboard',
            'cost_unit' => 'm2',
            'cost_per_unit' => 3.20,
        ]);

        $this->assertSame(
            $this->alfa->id,
            $comUsuarioLogado->tenant_id,
            'o creating da trait tem que sobrescrever o tenant_id vindo do payload',
        );
    }

    /* ── Cache ─────────────────────────────────────────────────────────── */

    #[Test]
    public function cada_empresa_recebe_a_propria_configuracao_de_custos(): void
    {
        /*
         * Regressão do vazamento mais traiçoeiro do multi-inquilino: o cache
         * responde ANTES da query, então o TenantScope nunca chega a ser
         * aplicado. Com chave única e global, a segunda empresa era precificada
         * com os custos da primeira — e a ordem das leituras decidia quem
         * contaminava quem.
         */
        config()->set('cache.default', 'array');
        cache()->purge('array');

        CostSetting::factory()->create([
            'tenant_id' => $this->alfa->id,
            'labor_hour_rate' => 30.00,
        ]);

        CostSetting::factory()->create([
            'tenant_id' => $this->beta->id,
            'labor_hour_rate' => 95.00,
        ]);

        $this->actingAs($this->usuarioAlfa);
        $this->assertSame(30.00, CostSetting::current()->labor_hour_rate);

        $this->actingAs($this->usuarioBeta);
        $this->assertSame(95.00, CostSetting::current()->labor_hour_rate);

        // E de volta: a leitura da Beta não pode ter sobrescrito a da Alfa.
        $this->actingAs($this->usuarioAlfa);
        $this->assertSame(30.00, CostSetting::current()->labor_hour_rate);
    }

    /* ── Sequência de referências ──────────────────────────────────────── */

    #[Test]
    public function cada_empresa_numera_os_orcamentos_a_partir_do_um(): void
    {
        $primeiroDaAlfa = $this->criarOrcamento($this->alfa, $this->usuarioAlfa);
        $segundoDaAlfa = $this->criarOrcamento($this->alfa, $this->usuarioAlfa);
        $primeiroDaBeta = $this->criarOrcamento($this->beta, $this->usuarioBeta);

        $ano = now()->year;

        $this->assertSame("ORC-{$ano}-000001", $primeiroDaAlfa->reference);
        $this->assertSame("ORC-{$ano}-000002", $segundoDaAlfa->reference);

        // A Beta começa do 1: um contador global a faria começar do 3 e
        // entregaria o volume da Alfa junto.
        $this->assertSame("ORC-{$ano}-000001", $primeiroDaBeta->reference);
    }

    /* ── Admin de plataforma ───────────────────────────────────────────── */

    #[Test]
    public function o_admin_de_plataforma_atravessa_as_empresas(): void
    {
        Material::factory()->create(['tenant_id' => $this->alfa->id]);
        Material::factory()->create(['tenant_id' => $this->beta->id]);

        $this->actingAs(User::factory()->platformAdmin()->create());

        $this->assertSame(2, Material::count());
    }

    #[Test]
    public function o_admin_da_empresa_nao_e_admin_da_plataforma(): void
    {
        Material::factory()->create(['tenant_id' => $this->alfa->id]);
        Material::factory()->create(['tenant_id' => $this->beta->id]);

        /*
         * A distinção que o multi-inquilino introduz: papel `admin` deixou de
         * significar "dono do sistema". Quem tem empresa é dono DELA, e o poder
         * total termina na fronteira do próprio tenant.
         */
        $adminDaAlfa = User::factory()->create([
            'tenant_id' => $this->alfa->id,
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($adminDaAlfa);

        $this->assertTrue($adminDaAlfa->isAdmin());
        $this->assertFalse($adminDaAlfa->isPlatformAdmin());
        $this->assertSame(1, Material::count());
    }

    /* ── Exclusão em cascata ───────────────────────────────────────────── */

    #[Test]
    public function excluir_a_empresa_leva_junto_os_dados_dela(): void
    {
        $this->criarOrcamento($this->beta, $this->usuarioBeta);
        CostSetting::factory()->create(['tenant_id' => $this->beta->id]);

        $sobrouDaAlfa = Material::factory()->create(['tenant_id' => $this->alfa->id]);

        $this->beta->delete();

        // Sem escopo: a verificação precisa enxergar a base inteira para provar
        // que as linhas sumiram de verdade, e não só do ponto de vista de alguém.
        $this->assertSame(0, Quote::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->beta->id)->withTrashed()->count());

        $this->assertDatabaseMissing('users', ['id' => $this->usuarioBeta->id]);
        $this->assertDatabaseHas('materials', ['id' => $sobrouDaAlfa->id]);
    }

    /* ── Apoio ─────────────────────────────────────────────────────────── */

    private function criarOrcamento(Tenant $tenant, User $user): Quote
    {
        $material = Material::factory()->create(['tenant_id' => $tenant->id]);

        return Quote::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'material_id' => $material->id,
            'box_model' => 'rsc',
            'width_mm' => 300,
            'height_mm' => 200,
            'depth_mm' => 150,
            'quantity' => 100,
            'waste_percent' => 10,
            'production_minutes_per_unit' => 2.5,
            'profit_margin_percent' => 30,
            'client_name' => 'Cliente de teste',
            'area_m2_per_unit' => 0.32725,
            'area_m2_total' => 32.725,
            'material_cost' => 100.0,
            'labor_cost' => 10.0,
            'machine_cost' => 10.0,
            'energy_cost' => 1.0,
            'overhead_cost' => 0.0,
            'unit_cost' => 5.0,
            'unit_price' => 6.5,
            'total_cost' => 500.0,
            'total_price' => 650.0,
            'profit_amount' => 150.0,
            // Obrigatório e sem default no banco: é o congelamento dos
            // parâmetros vigentes que mantém o orçamento emitido imutável.
            'pricing_snapshot' => [],
        ]);
    }
}

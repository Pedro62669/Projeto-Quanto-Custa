<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Equipment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pricing\DepreciationCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Inventário de máquinas e rateio da depreciação.
 *
 * O que está sob teste não é o CRUD — é a aritmética que vira preço, e as
 * bordas onde ela quebra: vida útil zero (divisão por zero), produção zero
 * (custo infinito) e o vazamento do parque de uma empresa no preço da outra.
 */
class EquipmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->create();
        $this->admin = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::Admin,
        ]);
    }

    /* ── As grandezas derivadas ────────────────────────────────────────── */

    #[Test]
    public function a_depreciacao_mensal_e_o_valor_dividido_pela_vida_util(): void
    {
        $maquina = Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 12000.00,
            'useful_life_months' => 60,
        ]);

        $this->assertSame(200.00, $maquina->monthly_depreciation);
        $this->assertSame(2400.00, $maquina->annual_depreciation);
    }

    #[Test]
    public function a_anual_fecha_com_doze_vezes_a_mensal_exibida(): void
    {
        /*
         * 10.000 / 60 = 166,666... A anual deriva da mensal JÁ ARREDONDADA
         * (166,67 × 12 = 2.000,04), e não do valor cheio (2.000,00). A diferença
         * de quatro centavos é de propósito: o usuário que conferir na
         * calculadora precisa chegar no número da tela.
         */
        $maquina = Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 10000.00,
            'useful_life_months' => 60,
        ]);

        $this->assertSame(166.67, $maquina->monthly_depreciation);
        $this->assertSame(2000.04, $maquina->annual_depreciation);
        $this->assertSame(
            round($maquina->monthly_depreciation * 12, 2),
            $maquina->annual_depreciation,
        );
    }

    #[Test]
    public function vida_util_zero_nao_derruba_a_listagem(): void
    {
        /*
         * A Request barra o zero, mas seeder, import e console não passam por
         * ela. Um registro defeituoso não pode estourar divisão por zero e
         * derrubar o inventário inteiro nem a simulação de preço.
         */
        $maquina = Equipment::factory()->make([
            'tenant_id' => $this->empresa->id,
            'useful_life_months' => 0,
        ]);

        $this->assertSame(0.0, $maquina->monthly_depreciation);
        $this->assertSame(0.0, $maquina->annual_depreciation);
    }

    #[Test]
    public function as_derivadas_acompanham_a_serializacao(): void
    {
        $maquina = Equipment::factory()->create(['tenant_id' => $this->empresa->id]);

        $this->assertArrayHasKey('monthly_depreciation', $maquina->toArray());
        $this->assertArrayHasKey('annual_depreciation', $maquina->toArray());
    }

    /* ── O rateio ──────────────────────────────────────────────────────── */

    #[Test]
    public function o_custo_por_unidade_rateia_o_parque_inteiro_pela_producao(): void
    {
        $this->actingAs($this->admin);

        Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 12000.00, 'useful_life_months' => 60,   // 200,00
        ]);
        Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 39000.00, 'useful_life_months' => 60,   // 650,00
        ]);

        $calculadora = app(DepreciationCalculator::class);

        $this->assertSame(850.00, $calculadora->monthlyTotal());

        // 850,00 / 5.000 peças = 0,17 por peça.
        $this->assertSame(0.17, $calculadora->perUnit(5000));
    }

    #[Test]
    public function produzir_menos_encarece_cada_peca(): void
    {
        $this->actingAs($this->admin);

        Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 12000.00, 'useful_life_months' => 60,
        ]);

        $calculadora = app(DepreciationCalculator::class);

        /*
         * A relação que justifica pedir a produção mensal ao usuário em vez de
         * estimá-la: o custo fixo não muda, então dividir por dez vezes menos
         * peças multiplica por dez o que cada peça carrega.
         */
        $this->assertSame(0.04, $calculadora->perUnit(5000));
        $this->assertSame(0.40, $calculadora->perUnit(500));
    }

    #[Test]
    public function producao_zero_falha_de_forma_explicita(): void
    {
        $this->actingAs($this->admin);
        Equipment::factory()->create(['tenant_id' => $this->empresa->id]);

        /*
         * Zero não é "sem depreciação": é uma pergunta sem resposta. Devolver
         * 0.0 esconderia o custo justamente no cenário em que ele mais pesa.
         */
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/produção mensal/');

        app(DepreciationCalculator::class)->perUnit(0);
    }

    #[Test]
    public function sem_maquinas_o_rateio_e_zero(): void
    {
        $this->actingAs($this->admin);

        $calculadora = app(DepreciationCalculator::class);

        $this->assertSame(0.0, $calculadora->monthlyTotal());
        $this->assertSame(0.0, $calculadora->perUnit(1000));
    }

    /* ── Isolamento ────────────────────────────────────────────────────── */

    #[Test]
    public function o_parque_de_outra_empresa_nao_entra_no_rateio(): void
    {
        $vizinha = Tenant::factory()->create();

        Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 12000.00, 'useful_life_months' => 60,   // 200,00
        ]);
        Equipment::factory()->create([
            'tenant_id' => $vizinha->id,
            'purchase_value' => 600000.00, 'useful_life_months' => 60,  // 10.000,00
        ]);

        $this->actingAs($this->admin);

        // A máquina da vizinha entraria com R$ 10.000/mês e multiplicaria por
        // 51 o custo de cada peça desta empresa.
        $this->assertSame(200.00, app(DepreciationCalculator::class)->monthlyTotal());
    }

    /* ── A API ─────────────────────────────────────────────────────────── */

    #[Test]
    public function a_rota_exige_admin(): void
    {
        $comum = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::User,
        ]);

        $this->actingAs($comum)->getJson('/api/admin/equipment')->assertForbidden();
    }

    #[Test]
    public function o_admin_cadastra_uma_maquina(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/equipment', [
                'name' => 'Vincadeira Heidelberg',
                'purchase_value' => 12000.00,
                'useful_life_months' => 60,
            ])
            ->assertCreated()
            ->assertJsonPath('data.monthly_depreciation', 200)
            ->assertJsonPath('data.annual_depreciation', 2400);

        $this->assertDatabaseHas('equipment', [
            'name' => 'Vincadeira Heidelberg',
            'tenant_id' => $this->empresa->id,
        ]);
    }

    #[Test]
    public function a_vida_util_zero_e_recusada_no_cadastro(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/equipment', [
                'name' => 'Máquina impossível',
                'purchase_value' => 12000.00,
                'useful_life_months' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('useful_life_months');
    }

    #[Test]
    public function a_listagem_nao_mostra_maquina_de_outra_empresa(): void
    {
        $vizinha = Tenant::factory()->create();
        Equipment::factory()->create(['tenant_id' => $vizinha->id, 'name' => 'Prensa da vizinha']);
        Equipment::factory()->create(['tenant_id' => $this->empresa->id, 'name' => 'Prensa própria']);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/equipment')->assertOk();

        $nomes = collect($response->json('data'))->pluck('name');

        $this->assertContains('Prensa própria', $nomes);
        $this->assertNotContains('Prensa da vizinha', $nomes);
    }

    #[Test]
    public function pedir_a_maquina_de_outra_empresa_devolve_404(): void
    {
        $vizinha = Tenant::factory()->create();
        $alheia = Equipment::factory()->create(['tenant_id' => $vizinha->id]);

        // 404 e não 403: um 403 confirmaria que o registro existe.
        $this->actingAs($this->admin)
            ->getJson("/api/admin/equipment/{$alheia->id}")
            ->assertNotFound();
    }

    #[Test]
    public function a_rota_de_impacto_explica_o_numero(): void
    {
        Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 12000.00, 'useful_life_months' => 60,
        ]);

        $this->actingAs($this->admin)
            ->getJson('/api/admin/equipment/depreciation-impact?monthly_production=5000')
            ->assertOk()
            ->assertJsonPath('data.monthly_total', 200)
            ->assertJsonPath('data.cost_per_unit', 0.04)
            ->assertJsonPath('data.equipment_count', 1);
    }

    #[Test]
    public function a_rota_de_impacto_nao_colide_com_o_binding_do_id(): void
    {
        /*
         * Regressão de ordem de rotas: declarada depois do apiResource, a URL
         * "equipment/depreciation-impact" casaria com "equipment/{equipment}" e
         * o Laravel tentaria resolver "depreciation-impact" como id.
         */
        $this->actingAs($this->admin)
            ->getJson('/api/admin/equipment/depreciation-impact?monthly_production=1000')
            ->assertOk()
            ->assertJsonStructure(['data' => ['monthly_total', 'cost_per_unit']]);
    }

    #[Test]
    public function remover_a_maquina_a_tira_do_rateio(): void
    {
        $maquina = Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 12000.00, 'useful_life_months' => 60,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/equipment/{$maquina->id}")
            ->assertOk();

        $this->assertDatabaseMissing('equipment', ['id' => $maquina->id]);
        $this->assertSame(0.0, app(DepreciationCalculator::class)->monthlyTotal());
    }
}

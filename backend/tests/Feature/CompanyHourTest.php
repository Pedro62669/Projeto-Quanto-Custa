<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EfficiencyScenario;
use App\Enums\UserRole;
use App\Models\CostSetting;
use App\Models\Equipment;
use App\Models\FixedCost;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pricing\CompanyHourCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Custo da hora e do minuto da empresa.
 *
 * Os números dos testes são redondos de propósito: R$ 8.800 de despesa em 176
 * horas pagas dá exatamente R$ 50/h a 100%. Quando um teste falha, ele acusa a
 * fórmula — não um centavo de arredondamento vindo da fixture.
 */
class CompanyHourTest extends TestCase
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

    /** R$ 8.800/mês de despesa fixa: 176 horas pagas × R$ 50. */
    private function despesaDe(float $valor = 8800.00): FixedCost
    {
        return FixedCost::factory()->create([
            'tenant_id' => $this->empresa->id,
            'name' => 'Aluguel',
            'monthly_amount' => $valor,
        ]);
    }

    private function calculadora(): CompanyHourCalculator
    {
        return app(CompanyHourCalculator::class);
    }

    /* ── A aritmética ──────────────────────────────────────────────────── */

    #[Test]
    public function o_fator_de_eficiencia_encarece_a_hora(): void
    {
        $this->actingAs($this->admin);
        $this->despesaDe(8800.00);

        $resultado = $this->calculadora()->calculate(8, 22, EfficiencyScenario::Recommended, false);

        // 8 × 22 = 176 horas pagas.
        $this->assertSame(176.0, $resultado['monthly_hours']);

        $cenarios = collect($resultado['comparison'])->keyBy('efficiency_percent');

        // 100%: 8.800 / 176 = 50,00
        $this->assertSame(176.0, $cenarios[100]['productive_hours']);
        $this->assertSame(50.00, $cenarios[100]['hour_cost']);

        // 85%: 8.800 / 149,6 = 58,82
        $this->assertSame(149.6, $cenarios[85]['productive_hours']);
        $this->assertSame(58.82, $cenarios[85]['hour_cost']);

        // 75%: 8.800 / 132 = 66,67
        $this->assertSame(132.0, $cenarios[75]['productive_hours']);
        $this->assertSame(66.67, $cenarios[75]['hour_cost']);
    }

    #[Test]
    public function o_custo_do_minuto_fecha_com_o_da_hora_exibida(): void
    {
        $this->actingAs($this->admin);
        $this->despesaDe(8800.00);

        $resultado = $this->calculadora()->calculate(8, 22, EfficiencyScenario::Recommended, false);

        $ativo = $resultado['active_scenario'];

        /*
         * O minuto deriva da hora JÁ ARREDONDADA. 58,82 / 60 = 0,9803, e
         * 0,9803 × 60 volta a 58,82 — que é o número na tela. Derivar da
         * divisão cheia daria 0,98039..., e o usuário que conferisse na
         * calculadora acharia um erro que não existe.
         */
        $this->assertSame(58.82, $ativo['hour_cost']);
        $this->assertSame(0.9803, $ativo['minute_cost']);
        $this->assertSame($ativo['hour_cost'], round($ativo['minute_cost'] * 60, 2));
    }

    #[Test]
    public function o_cenario_ativo_e_o_escolhido_pelo_usuario(): void
    {
        $this->actingAs($this->admin);
        $this->despesaDe(8800.00);

        $conservador = $this->calculadora()->calculate(8, 22, EfficiencyScenario::Conservative, false);

        $this->assertSame(75, $conservador['active_scenario']['efficiency_percent']);
        $this->assertSame(66.67, $conservador['active_scenario']['hour_cost']);

        // E a comparação continua trazendo os três, independente do ativo.
        $this->assertCount(3, $conservador['comparison']);
    }

    #[Test]
    public function jornada_zerada_falha_de_forma_explicita(): void
    {
        $this->actingAs($this->admin);
        $this->despesaDe();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/jornada/');

        $this->calculadora()->calculate(0, 22, EfficiencyScenario::Recommended, false);
    }

    /* ── A base de custo ───────────────────────────────────────────────── */

    #[Test]
    public function a_depreciacao_entra_na_base_quando_a_opcao_esta_ligada(): void
    {
        $this->actingAs($this->admin);
        $this->despesaDe(8800.00);

        // 12.000 / 60 = R$ 200,00 por mês.
        Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 12000.00,
            'useful_life_months' => 60,
        ]);

        $sem = $this->calculadora()->calculate(8, 22, EfficiencyScenario::Optimistic, false);
        $com = $this->calculadora()->calculate(8, 22, EfficiencyScenario::Optimistic, true);

        $this->assertSame(8800.00, $sem['cost_base']['total']);
        $this->assertSame(0.0, $sem['cost_base']['depreciation']);

        $this->assertSame(9000.00, $com['cost_base']['total']);
        $this->assertSame(200.00, $com['cost_base']['depreciation']);

        // E o efeito chega no preço: 9.000 / 176 = 51,14 contra 50,00.
        $this->assertSame(50.00, $sem['active_scenario']['hour_cost']);
        $this->assertSame(51.14, $com['active_scenario']['hour_cost']);
    }

    #[Test]
    public function a_despesa_desativada_sai_da_conta(): void
    {
        $this->actingAs($this->admin);

        $this->despesaDe(8800.00);
        FixedCost::factory()->inactive()->create([
            'tenant_id' => $this->empresa->id,
            'name' => 'Marketing (cortado)',
            'monthly_amount' => 5000.00,
        ]);

        /*
         * A simulação de corte é o motivo de o flag existir: desligar a linha
         * tira o valor da conta sem perder o número, e religar desfaz.
         */
        $this->assertSame(8800.00, $this->calculadora()->fixedCostsTotal());
    }

    #[Test]
    public function sem_despesa_cadastrada_a_hora_custa_zero(): void
    {
        $this->actingAs($this->admin);

        $resultado = $this->calculadora()->calculate(8, 22, EfficiencyScenario::Recommended, true);

        $this->assertSame(0.0, $resultado['cost_base']['total']);
        $this->assertSame(0.0, $resultado['active_scenario']['hour_cost']);
    }

    /* ── Isolamento ────────────────────────────────────────────────────── */

    #[Test]
    public function a_despesa_de_outra_empresa_nao_entra_na_conta(): void
    {
        $vizinha = Tenant::factory()->create();

        $this->despesaDe(8800.00);
        FixedCost::factory()->create([
            'tenant_id' => $vizinha->id,
            'monthly_amount' => 99000.00,
        ]);

        $this->actingAs($this->admin);

        $this->assertSame(8800.00, $this->calculadora()->fixedCostsTotal());
    }

    /* ── A API ─────────────────────────────────────────────────────────── */

    #[Test]
    public function a_rota_exige_admin(): void
    {
        $comum = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::User,
        ]);

        $this->actingAs($comum)->getJson('/api/admin/company-hour')->assertForbidden();
    }

    #[Test]
    public function a_rota_devolve_o_ativo_e_a_matriz_dos_tres(): void
    {
        $this->despesaDe(8800.00);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/company-hour?hours_per_day=8&days_per_month=22&efficiency_percent=75&include_depreciation=0')
            ->assertOk();

        $response
            ->assertJsonPath('data.active_scenario.efficiency_percent', 75)
            ->assertJsonPath('data.active_scenario.hour_cost', 66.67)
            ->assertJsonCount(3, 'data.comparison')
            ->assertJsonPath('data.comparison.0.efficiency_percent', 100)
            ->assertJsonPath('data.comparison.1.efficiency_percent', 85)
            ->assertJsonPath('data.comparison.2.efficiency_percent', 75);

        // O rótulo acompanha: a interface não deve reinventar os nomes.
        $this->assertSame('Recomendado (equilibrado)', $response->json('data.comparison.1.label'));
    }

    #[Test]
    public function os_defaults_sao_oito_horas_vinte_e_dois_dias_e_oitenta_e_cinco(): void
    {
        $this->despesaDe(8800.00);

        $this->actingAs($this->admin)
            ->getJson('/api/admin/company-hour')
            ->assertOk()
            ->assertJsonPath('data.parameters.hours_per_day', 8)
            ->assertJsonPath('data.parameters.days_per_month', 22)
            ->assertJsonPath('data.parameters.efficiency_percent', 85)
            // Depreciação entra por padrão: esquecer de LIGÁ-LA quebra o
            // negócio; esquecer de desligá-la só encarece um pouco.
            ->assertJsonPath('data.parameters.include_depreciation', true);
    }

    #[Test]
    public function um_fator_fora_dos_tres_cenarios_e_recusado(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/admin/company-hour?efficiency_percent=93')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('efficiency_percent');
    }

    /* ── Impacto da depreciação por peça (volume persistido) ───────────── */

    #[Test]
    public function o_rateio_por_peca_usa_o_volume_gravado(): void
    {
        $this->actingAs($this->admin);
        $this->despesaDe();

        // R$ 12.000 / 60 = R$ 200/mês de depreciação.
        Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 12000.00,
            'useful_life_months' => 60,
        ]);

        // 200 / 500 peças = R$ 0,40 por peça.
        $resultado = $this->calculadora()->calculate(8, 22, EfficiencyScenario::Recommended, true, 500);

        $this->assertSame(0.40, $resultado['depreciation_per_unit']);
    }

    #[Test]
    public function o_rateio_por_peca_ignora_o_botao_de_depreciacao(): void
    {
        $this->actingAs($this->admin);
        $this->despesaDe();

        Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 12000.00,
            'useful_life_months' => 60,
        ]);

        $ligado = $this->calculadora()->calculate(8, 22, EfficiencyScenario::Recommended, true, 500);
        $desligado = $this->calculadora()->calculate(8, 22, EfficiencyScenario::Recommended, false, 500);

        /*
         * "Quanto de máquina tem nesta caixa" não muda porque o usuário
         * desligou o botão: desligar muda como ele COBRA, não o que consome.
         * O botão move a base da hora; o rateio por peça é outra pergunta.
         */
        $this->assertSame(0.40, $ligado['depreciation_per_unit']);
        $this->assertSame(0.40, $desligado['depreciation_per_unit']);

        // Mas a base da hora, essa sim, muda.
        $this->assertNotSame($ligado['cost_base']['total'], $desligado['cost_base']['total']);
    }

    #[Test]
    public function volume_nao_declarado_nao_derruba_o_calculo(): void
    {
        $this->actingAs($this->admin);
        $this->despesaDe();

        Equipment::factory()->create(['tenant_id' => $this->empresa->id]);

        /*
         * Uma configuração incompleta não pode derrubar toda simulação de preço
         * do sistema. Quem PERGUNTA o impacto explicitamente recebe exceção —
         * ver DepreciationCalculator::perUnit().
         */
        $resultado = $this->calculadora()->calculate(8, 22, EfficiencyScenario::Recommended, true, 0);

        $this->assertSame(0.0, $resultado['depreciation_per_unit']);
        $this->assertGreaterThan(0, $resultado['active_scenario']['hour_cost']);
    }

    #[Test]
    public function a_rota_usa_o_volume_da_configuracao_vigente(): void
    {
        $this->despesaDe();

        Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 12000.00,
            'useful_life_months' => 60,
        ]);

        CostSetting::factory()->create([
            'tenant_id' => $this->empresa->id,
            'monthly_production_volume' => 400,
            'company_includes_depreciation' => true,
        ]);

        // 200 / 400 = R$ 0,50 por peça, sem precisar passar nada na query.
        $this->actingAs($this->admin)
            ->getJson('/api/admin/company-hour')
            ->assertOk()
            ->assertJsonPath('data.parameters.monthly_production_volume', 400)
            ->assertJsonPath('data.depreciation_per_unit', 0.5);
    }

    #[Test]
    public function a_query_string_sobrepoe_o_volume_gravado(): void
    {
        $this->despesaDe();

        Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 12000.00,
            'useful_life_months' => 60,
        ]);

        CostSetting::factory()->create([
            'tenant_id' => $this->empresa->id,
            'monthly_production_volume' => 400,
        ]);

        // O painel serve para perguntar "e se eu produzir o dobro?" sem
        // publicar uma versão nova da configuração.
        $this->actingAs($this->admin)
            ->getJson('/api/admin/company-hour?monthly_production_volume=800&include_depreciation=1')
            ->assertOk()
            ->assertJsonPath('data.depreciation_per_unit', 0.25);
    }

    #[Test]
    public function o_crud_de_custos_fixos_traz_o_total_do_mes(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/fixed-costs', ['name' => 'Contador', 'monthly_amount' => 600.00])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/fixed-costs', ['name' => 'Internet', 'monthly_amount' => 200.00])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->getJson('/api/admin/fixed-costs')
            ->assertOk()
            ->assertJsonPath('meta.monthly_total', 800);

        $this->assertDatabaseHas('fixed_costs', [
            'name' => 'Contador',
            'tenant_id' => $this->empresa->id,
        ]);
    }
}

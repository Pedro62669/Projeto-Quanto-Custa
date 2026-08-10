<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CostSetting;
use App\Models\Equipment;
use App\Models\FixedCost;
use App\Models\Material;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A costura entre os módulos da Fase 2 e o motor de preço.
 *
 * O risco desta integração não é errar a conta — é contar duas vezes. Aluguel e
 * depreciação já entravam no preço por estimativa (`overhead_percent` e
 * `machine_hour_rate`), e ligar os módulos novos por cima cobraria o mesmo
 * dinheiro de novo. É isso que os testes abaixo perseguem.
 */
class CompanyHourPricingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $usuario;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->create();
        $this->usuario = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::Admin,
        ]);

        $this->material = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'cost_per_unit' => 3.20,
            'thickness_mm' => 0.0,
        ]);

        // R$ 8.800/mês ÷ 149,6 h produtivas (85%) = R$ 58,82/h = R$ 0,9803/min.
        FixedCost::factory()->create([
            'tenant_id' => $this->empresa->id,
            'monthly_amount' => 8800.00,
        ]);
    }

    /** @return array<string, mixed> */
    private function spec(array $overrides = []): array
    {
        return [
            'material_id' => $this->material->id,
            'box_model' => 'rsc',
            'width_mm' => 300, 'height_mm' => 200, 'depth_mm' => 150,
            'quantity' => 100,
            'waste_percent' => 10,
            'production_minutes_per_unit' => 2.5,
            'profit_margin_percent' => 30,
            'pricing_mode' => 'markup',
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function configurar(array $overrides = []): CostSetting
    {
        return CostSetting::factory()->create([
            'tenant_id' => $this->empresa->id,
            ...$overrides,
        ]);
    }

    /* ── O modo desligado não muda nada ────────────────────────────────── */

    #[Test]
    public function com_o_modo_desligado_o_preco_e_bit_a_bit_o_de_antes(): void
    {
        $this->configurar([
            'labor_hour_rate' => 30.00,
            'overhead_percent' => 12.0,
            'use_company_hour' => false,
        ]);

        // Máquinas e despesas cadastradas, mas o modo está desligado: não podem
        // vazar para o preço. É o que protege todo orçamento já emitido.
        Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 600000.00, 'useful_life_months' => 60,
        ]);

        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())
            ->assertOk();

        // 2,5 min = 0,0416667 h × R$ 30 = R$ 1,25 de mão de obra.
        $this->assertSame(1.25, $response->json('data.labor_cost'));

        // E o rateio percentual continua valendo.
        $this->assertGreaterThan(0, $response->json('data.overhead_cost'));
    }

    /* ── O modo ligado ─────────────────────────────────────────────────── */

    #[Test]
    public function com_o_modo_ligado_a_mao_de_obra_sai_do_custo_do_minuto(): void
    {
        $this->configurar([
            'labor_hour_rate' => 30.00,
            'use_company_hour' => true,
            'company_efficiency_percent' => 85,
            'company_includes_depreciation' => false,
        ]);

        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())
            ->assertOk();

        /*
         * 2,5 min × R$ 0,9803 = R$ 2,4507 — e NÃO os R$ 1,25 que o
         * labor_hour_rate de R$ 30/h produziria. Com o modo ligado aquele campo
         * passa a ser ignorado pelo motor.
         */
        $this->assertSame(2.4507, $response->json('data.labor_cost'));
    }

    #[Test]
    public function o_rateio_percentual_e_ignorado_com_o_modo_ligado(): void
    {
        $this->configurar([
            'overhead_percent' => 35.0,
            'use_company_hour' => true,
            'company_includes_depreciation' => false,
        ]);

        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())
            ->assertOk();

        /*
         * O teste que justifica a integração inteira. O rateio existe para
         * cobrar aluguel, contador e energia — exatamente o que a hora-empresa
         * já cobrou. Somar os dois aplicaria 35% sobre um custo que já contém
         * essas despesas, e o erro cresceria junto com o tempo de produção.
         */
        // 0 e não 0.0: o JSON serializa zero float como inteiro.
        $this->assertSame(0, $response->json('data.overhead_cost'));
    }

    #[Test]
    public function a_depreciacao_chega_ao_preco_pela_hora_empresa(): void
    {
        $this->configurar([
            'use_company_hour' => true,
            'company_includes_depreciation' => true,
        ]);

        $semMaquina = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())->assertOk();

        // R$ 12.000 / 60 meses = R$ 200/mês na base da hora-empresa.
        Equipment::factory()->create([
            'tenant_id' => $this->empresa->id,
            'purchase_value' => 12000.00, 'useful_life_months' => 60,
        ]);

        $comMaquina = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())->assertOk();

        /*
         * A máquina encarece a peça sem virar uma linha nova de custo: ela
         * entra pela base da hora-empresa, que é o único caminho que NÃO
         * duplica com `machine_hour_rate` (definido como "depreciação +
         * manutenção").
         */
        $this->assertGreaterThan(
            $semMaquina->json('data.labor_cost'),
            $comMaquina->json('data.labor_cost'),
        );

        $this->assertGreaterThan(
            $semMaquina->json('data.unit_price'),
            $comMaquina->json('data.unit_price'),
        );
    }

    #[Test]
    public function o_fator_de_eficiencia_versionado_move_o_preco(): void
    {
        /*
         * A primeira versão precisa começar no PASSADO.
         *
         * `CostSetting::current()` só enxerga vigência já iniciada, e a segunda
         * versão tem que ser mais recente que a primeira sem cair no futuro —
         * agendar para daqui a um segundo faria o motor continuar usando a
         * antiga, que é justamente o comportamento correto dela.
         */
        $this->configurar([
            'use_company_hour' => true,
            'company_efficiency_percent' => 100,
            'company_includes_depreciation' => false,
            'effective_from' => now()->subDay(),
        ]);

        $otimista = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())->assertOk();

        // Nova VERSÃO da configuração — a tabela não permite editar a vigente.
        $this->configurar([
            'use_company_hour' => true,
            'company_efficiency_percent' => 75,
            'company_includes_depreciation' => false,
            'effective_from' => now(),
        ]);

        $conservador = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())->assertOk();

        // 8.800/176 = 50,00/h contra 8.800/132 = 66,67/h.
        $this->assertSame(2.0833, $otimista->json('data.labor_cost'));
        $this->assertSame(2.7780, $conservador->json('data.labor_cost'));
    }

    /* ── O que a empresa vizinha não pode fazer ────────────────────────── */

    #[Test]
    public function a_despesa_da_vizinha_nao_encarece_meu_orcamento(): void
    {
        $this->configurar([
            'use_company_hour' => true,
            'company_includes_depreciation' => true,
        ]);

        $vizinha = Tenant::factory()->create();
        FixedCost::factory()->create([
            'tenant_id' => $vizinha->id,
            'monthly_amount' => 500000.00,
        ]);

        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())
            ->assertOk();

        // Só os R$ 8.800 desta empresa: 2,5 × 0,9803.
        // 2,5 × 0,9803 = 2,450750 — o binário guarda 2,4507499..., e o
        // arredondamento em 4 casas do motor devolve 2,4507.
        $this->assertSame(2.4507, $response->json('data.labor_cost'));
    }

    /* ── O preview do navegador usa o mesmo número ─────────────────────── */

    #[Test]
    public function a_configuracao_vigente_entrega_o_minuto_ja_calculado(): void
    {
        $this->configurar([
            'use_company_hour' => true,
            'company_includes_depreciation' => false,
        ]);

        /*
         * O motor TypeScript não soma despesas fixas — ele consome este número.
         * Duas implementações da mesma soma seriam exatamente a divergência que
         * a suíte de paridade existe para impedir.
         */
        $this->actingAs($this->usuario)
            ->getJson('/api/admin/cost-settings/current')
            ->assertOk()
            ->assertJsonPath('data.use_company_hour', true)
            ->assertJsonPath('data.company_minute_cost', 0.9803);
    }

    #[Test]
    public function com_o_modo_desligado_o_minuto_vem_nulo(): void
    {
        $this->configurar(['use_company_hour' => false]);

        $this->actingAs($this->usuario)
            ->getJson('/api/admin/cost-settings/current')
            ->assertOk()
            ->assertJsonPath('data.company_minute_cost', null);
    }

    /* ── O aviso de dupla contagem ─────────────────────────────────────── */

    #[Test]
    public function publicar_o_modo_com_hora_maquina_cheia_avisa(): void
    {
        $response = $this->actingAs($this->usuario)
            ->postJson('/api/admin/cost-settings', [
                'energy_tariff_per_kwh' => 0.92,
                'machine_hour_rate' => 45.00,
                'machine_power_kw' => 7.5,
                'labor_hour_rate' => 28.00,
                'overhead_percent' => 12.0,
                'use_company_hour' => true,
                'company_includes_depreciation' => true,
            ])
            ->assertCreated();

        $avisos = implode(' ', $response->json('warnings'));

        $this->assertStringContainsString('MANUTENÇÃO', $avisos);
        $this->assertStringContainsString('rateio percentual é ignorado', $avisos);
    }

    #[Test]
    public function sem_o_modo_nao_ha_aviso(): void
    {
        $this->actingAs($this->usuario)
            ->postJson('/api/admin/cost-settings', [
                'energy_tariff_per_kwh' => 0.92,
                'machine_hour_rate' => 45.00,
                'machine_power_kw' => 7.5,
                'labor_hour_rate' => 28.00,
                'overhead_percent' => 12.0,
            ])
            ->assertCreated()
            ->assertJsonMissingPath('warnings');
    }

    /* ── O histórico ───────────────────────────────────────────────────── */

    #[Test]
    public function o_orcamento_gravado_congela_o_regime_usado(): void
    {
        $this->configurar([
            'use_company_hour' => true,
            'company_includes_depreciation' => false,
        ]);

        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes', [...$this->spec(), 'client_name' => 'Cliente'])
            ->assertCreated();

        $quote = Quote::query()->sole();

        // A versão do motor entra no snapshot: é ela que explica, daqui a um
        // ano, por que este orçamento tem o número que tem.
        $this->assertSame('1.4.0', $quote->pricing_snapshot['engine_version'] ?? null);

        // O QuoteResource aninha os custos, diferente do payload de simulação.
        $this->assertSame(2.4507, $response->json('data.costs.labor'));
    }
}

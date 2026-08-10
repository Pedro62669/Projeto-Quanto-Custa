<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InstallmentStatus;
use App\Enums\UserRole;
use App\Models\CostSetting;
use App\Models\FixedCost;
use App\Models\Installment;
use App\Models\Material;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\FinancialEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Painel financeiro: realizado × projetado, distribuição e ponto de equilíbrio.
 *
 * O ponto de equilíbrio é o número mais perigoso do sistema. Ele responde
 * "quanto preciso vender para não ter prejuízo", e um erro aqui não parece
 * erro: parece uma meta. Os testes abaixo fixam as DUAS correções feitas em
 * relação ao desenho original da fase — a fonte do custo fixo e a unidade da
 * margem.
 */
class FinancialDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->create();
        $this->usuario = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::Admin,
        ]);

        CostSetting::factory()->create([
            'tenant_id' => $this->empresa->id,
            'company_includes_depreciation' => false,
        ]);
    }

    private function motor(): FinancialEngine
    {
        return app(FinancialEngine::class);
    }

    /** Orçamento com custo de insumo conhecido: 100 peças × R$ 4 = R$ 400. */
    private function orcamento(float $totalPrice, float $insumoPorPeca = 4.00): Quote
    {
        $material = Material::factory()->create(['tenant_id' => $this->empresa->id]);

        return Quote::create([
            'tenant_id' => $this->empresa->id,
            'user_id' => $this->usuario->id,
            'material_id' => $material->id,
            'box_model' => 'rsc',
            'width_mm' => 300, 'height_mm' => 200, 'depth_mm' => 150,
            'quantity' => 100, 'waste_percent' => 10,
            'production_minutes_per_unit' => 2.5, 'profit_margin_percent' => 30,
            'client_name' => 'Cliente',
            'area_m2_per_unit' => 0.3, 'area_m2_total' => 30.0,
            'material_cost' => $insumoPorPeca, 'wrap_cost' => 0.0, 'hardware_cost' => 0.0,
            'labor_cost' => 1.0, 'machine_cost' => 1.0, 'energy_cost' => 0.5,
            'overhead_cost' => 0.0, 'unit_cost' => 10.5,
            'unit_price' => $totalPrice / 100,
            'total_cost' => 1050.0, 'total_price' => $totalPrice,
            'profit_amount' => 0.0, 'pricing_snapshot' => [],
        ]);
    }

    /* ── Realizado × projetado ─────────────────────────────────────────── */

    #[Test]
    public function realizado_conta_o_que_foi_quitado_e_projetado_o_que_vence(): void
    {
        $this->actingAs($this->usuario);

        $quote = $this->orcamento(3000.00);

        $this->postJson("/api/quotes/{$quote->id}/approve", ['installments' => 3])->assertOk();

        // Quita só a primeira parcela.
        $primeira = Installment::query()->orderBy('installment_number')->first();
        $primeira->settle(now());

        $metricas = $this->motor()->dashboardMetrics(now()->month, now()->year);

        /*
         * A distinção que justifica as duas tabelas: a primeira parcela virou
         * dinheiro (realizado), as três vencem em meses diferentes — só uma
         * neste (projetado).
         */
        $this->assertSame(1000.00, $metricas['revenue']['realized']);
        $this->assertSame(1000.00, $metricas['revenue']['projected']);
    }

    #[Test]
    public function o_saldo_realizado_pode_ser_negativo(): void
    {
        $this->actingAs($this->usuario);

        $saida = Transaction::factory()->exit()->create([
            'tenant_id' => $this->empresa->id,
            'amount' => 2500.00,
            'transaction_date' => now(),
        ]);

        $parcelas = $this->motor()->generateInstallments($saida, 1);
        $parcelas[0]->settle(now());

        $metricas = $this->motor()->dashboardMetrics(now()->month, now()->year);

        // Mês em que saiu mais do que entrou. Exibir o negativo É o ponto.
        $this->assertSame(-2500.00, $metricas['net_realized']);
    }

    /* ── Ponto de equilíbrio ───────────────────────────────────────────── */

    #[Test]
    public function o_custo_fixo_sai_das_despesas_e_nao_do_cost_setting(): void
    {
        $this->actingAs($this->usuario);

        FixedCost::factory()->create([
            'tenant_id' => $this->empresa->id,
            'monthly_amount' => 8800.00,
        ]);

        $metricas = $this->motor()->dashboardMetrics(now()->month, now()->year);

        /*
         * Desde a Fase 2, `cost_settings` guarda TAXAS (R$/hora) e a despesa
         * mensal mora em `fixed_costs`. O desenho original da Fase 4 mandava
         * puxar do CostSetting, o que devolveria uma taxa horária como se fosse
         * despesa do mês.
         */
        $this->assertSame(8800.00, $metricas['break_even']['fixed_cost']);
    }

    #[Test]
    public function a_margem_e_percentual_e_o_alvo_nao_estoura_cem_vezes(): void
    {
        $this->actingAs($this->usuario);

        FixedCost::factory()->create([
            'tenant_id' => $this->empresa->id,
            'monthly_amount' => 8800.00,
        ]);

        // Venda de R$ 10.000 com R$ 4.000 de insumo → margem de 60%.
        $quote = $this->orcamento(10000.00, insumoPorPeca: 40.00);
        $this->postJson("/api/quotes/{$quote->id}/approve")->assertOk();

        $equilibrio = $this->motor()->dashboardMetrics(now()->month, now()->year)['break_even'];

        $this->assertSame(60.00, $equilibrio['contribution_margin_percent']);

        /*
         * 8.800 / 0,60 = R$ 14.666,67.
         *
         * O documento definia a margem como FRAÇÃO (0,60) e ainda dividia por
         * `margem/100`, o que daria 8.800 / 0,006 = R$ 1.466.666,67 — cem vezes
         * o valor real. Um ponto de equilíbrio assim faria qualquer cartonagem
         * saudável parecer inviável, e a "meta" viraria motivo de desistência.
         */
        $this->assertSame(14666.67, $equilibrio['target_revenue']);
        $this->assertSame('margem-apurada', $equilibrio['basis']);
    }

    #[Test]
    public function sem_vendas_a_meta_e_o_proprio_custo_fixo(): void
    {
        $this->actingAs($this->usuario);

        FixedCost::factory()->create([
            'tenant_id' => $this->empresa->id,
            'monthly_amount' => 5000.00,
        ]);

        $equilibrio = $this->motor()->dashboardMetrics(now()->month, now()->year)['break_even'];

        // Sem venda não há margem a apurar — e a divisão por zero que o próprio
        // documento manda tratar. A resposta honesta é o custo fixo.
        $this->assertNull($equilibrio['contribution_margin_percent']);
        $this->assertSame(5000.00, $equilibrio['target_revenue']);
        $this->assertSame('sem-vendas', $equilibrio['basis']);
    }

    #[Test]
    public function margem_negativa_nao_produz_meta_astronomica(): void
    {
        $this->actingAs($this->usuario);

        FixedCost::factory()->create([
            'tenant_id' => $this->empresa->id,
            'monthly_amount' => 5000.00,
        ]);

        // Vende por R$ 1.000 gastando R$ 4.000 de insumo: margem de −300%.
        $quote = $this->orcamento(1000.00, insumoPorPeca: 40.00);
        $this->postJson("/api/quotes/{$quote->id}/approve")->assertOk();

        $equilibrio = $this->motor()->dashboardMetrics(now()->month, now()->year)['break_even'];

        /*
         * Com margem negativa, vender MAIS aumenta o prejuízo — não existe
         * faturamento que cubra o fixo. Dividir mesmo assim cuspiria um número
         * negativo ou gigante sem explicação; o `basis` deixa o painel alertar.
         */
        $this->assertLessThan(0, $equilibrio['contribution_margin_percent']);
        $this->assertSame(5000.00, $equilibrio['target_revenue']);
        $this->assertSame('margem-nao-positiva', $equilibrio['basis']);
    }

    #[Test]
    public function a_revenda_de_custo_desconhecido_fica_fora_da_margem(): void
    {
        $this->actingAs($this->usuario);

        FixedCost::factory()->create([
            'tenant_id' => $this->empresa->id,
            'monthly_amount' => 8800.00,
        ]);

        $quote = $this->orcamento(10000.00, insumoPorPeca: 40.00);
        $this->postJson("/api/quotes/{$quote->id}/approve")->assertOk();

        // Revenda de produto: o sistema não sabe o custo variável dela.
        $revenda = Transaction::factory()->create([
            'tenant_id' => $this->empresa->id,
            'amount' => 50000.00,
            'transaction_date' => now(),
        ]);
        $this->motor()->generateInstallments($revenda, 1);

        $equilibrio = $this->motor()->dashboardMetrics(now()->month, now()->year)['break_even'];

        /*
         * Incluir os R$ 50.000 com custo zero levaria a margem para 93% e
         * derrubaria o ponto de equilíbrio. É o erro perigoso: faz o negócio
         * parecer mais saudável do que é.
         */
        $this->assertSame(60.00, $equilibrio['contribution_margin_percent']);
    }

    /* ── Distribuição ──────────────────────────────────────────────────── */

    #[Test]
    public function a_distribuicao_soma_cem_por_cento(): void
    {
        $this->actingAs($this->usuario);

        $quote = $this->orcamento(3000.00);
        $this->postJson("/api/quotes/{$quote->id}/approve")->assertOk();
        Installment::query()->first()->settle(now());

        $revenda = Transaction::factory()->create([
            'tenant_id' => $this->empresa->id,
            'amount' => 1000.00,
            'transaction_date' => now(),
        ]);
        $this->motor()->generateInstallments($revenda, 1)[0]->settle(now());

        $distribuicao = $this->motor()
            ->dashboardMetrics(now()->month, now()->year)['revenue_distribution'];

        $this->assertCount(2, $distribuicao);

        // 3.000 de 4.000 = 75%; 1.000 de 4.000 = 25%.
        $this->assertSame('quote_sale', $distribuicao[0]['category']);
        $this->assertSame(75.0, $distribuicao[0]['percent']);
        $this->assertSame(25.0, $distribuicao[1]['percent']);

        $this->assertSame(100.0, array_sum(array_column($distribuicao, 'percent')));
    }

    #[Test]
    public function mes_sem_venda_nao_estoura_a_distribuicao(): void
    {
        $this->actingAs($this->usuario);

        $metricas = $this->motor()->dashboardMetrics(now()->month, now()->year);

        $this->assertSame([], $metricas['revenue_distribution']);
        $this->assertSame(0.0, $metricas['revenue']['realized']);
    }

    /* ── Atraso ────────────────────────────────────────────────────────── */

    #[Test]
    public function o_atraso_nao_se_esconde_no_recorte_do_mes(): void
    {
        $this->actingAs($this->usuario);

        $transacao = Transaction::factory()->create([
            'tenant_id' => $this->empresa->id,
            'amount' => 700.00,
            'transaction_date' => Carbon::parse('2026-03-10'),
        ]);

        $parcelas = $this->motor()->generateInstallments(
            $transacao, 1, Carbon::parse('2026-03-10'),
        );

        /*
         * Uma parcela de março que ninguém pagou continua sendo problema em
         * agosto. Filtrar o atraso pelo mês corrente a faria sumir do painel
         * justamente quando ela mais importa.
         */
        $metricas = $this->motor()->dashboardMetrics(8, 2026);

        $this->assertSame(1, $metricas['overdue']['count']);
        $this->assertSame(700.00, $metricas['overdue']['amount']);
        $this->assertTrue($parcelas[0]->fresh()->isOverdue());
    }

    /* ── Isolamento ────────────────────────────────────────────────────── */

    #[Test]
    public function o_painel_nao_soma_o_caixa_da_vizinha(): void
    {
        $vizinha = Tenant::factory()->create();

        $alheia = Transaction::factory()->create([
            'tenant_id' => $vizinha->id,
            'amount' => 99000.00,
            'transaction_date' => now(),
        ]);

        Installment::create([
            'tenant_id' => $vizinha->id,
            'transaction_id' => $alheia->id,
            'installment_number' => 1,
            'total_installments' => 1,
            'amount' => 99000.00,
            'due_date' => now(),
            'payment_date' => now(),
            'status' => InstallmentStatus::Completed,
        ]);

        $this->actingAs($this->usuario);

        $metricas = $this->motor()->dashboardMetrics(now()->month, now()->year);

        $this->assertSame(0.0, $metricas['revenue']['realized']);
    }

    /* ── A rota ────────────────────────────────────────────────────────── */

    #[Test]
    public function a_rota_devolve_o_mes_corrente_por_padrao(): void
    {
        FixedCost::factory()->create([
            'tenant_id' => $this->empresa->id,
            'monthly_amount' => 8800.00,
        ]);

        $this->actingAs($this->usuario)
            ->getJson('/api/financial/dashboard')
            ->assertOk()
            ->assertJsonPath('data.period.month', now()->month)
            ->assertJsonPath('data.period.year', now()->year)
            ->assertJsonPath('data.break_even.fixed_cost', 8800)
            ->assertJsonStructure([
                'data' => [
                    'revenue' => ['realized', 'projected'],
                    'expenses' => ['realized', 'projected'],
                    'net_realized',
                    'break_even' => ['fixed_cost', 'contribution_margin_percent', 'target_revenue', 'basis'],
                    'revenue_distribution',
                    'overdue' => ['count', 'amount'],
                ],
            ]);
    }

    #[Test]
    public function a_rota_recusa_mes_invalido(): void
    {
        $this->actingAs($this->usuario)
            ->getJson('/api/financial/dashboard?month=13')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');
    }
}

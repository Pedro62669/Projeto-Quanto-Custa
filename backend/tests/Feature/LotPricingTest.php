<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Enums\UserRole;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Custo derivado do lote de compra, com frete rateado.
 *
 * O buraco que isto fecha: até agora o frete era invisível. Quem paga R$ 400 de
 * entrega numa carga de chapas nunca via esse dinheiro no preço da caixa — na
 * melhor das hipóteses alguém lembrava de inflar a margem, na pior ele virava
 * prejuízo diluído, aparecendo só no fim do mês como "o caixa não fechou".
 *
 * O outro tema do arquivo é a CONVIVÊNCIA: nada disso pode quebrar quem já
 * cadastrou R$/m² direto. A empresa não vai recadastrar o estoque inteiro para
 * o sistema voltar a orçar.
 */
class LotPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CostSetting::factory()->create();

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    /* ── A conta ───────────────────────────────────────────────────────── */

    #[Test]
    public function o_custo_por_m2_sai_do_lote_com_o_frete_rateado(): void
    {
        /*
         * 100 folhas de 1000×2000mm (2m² cada) por R$ 900, mais R$ 100 de frete.
         *
         *   (900 + 100) ÷ 100 folhas = R$ 10,00 por folha
         *   R$ 10,00 ÷ 2m²            = R$ 5,00/m²
         *
         * Números escolhidos para o resultado ser conferível de cabeça: um teste
         * cujo valor esperado só o próprio código sabe calcular não prova nada.
         */
        $material = Material::factory()->create([
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 999.99, // deve ser IGNORADO
            'sheet_width_mm' => 1000,
            'sheet_length_mm' => 2000,
            'lot_quantity' => 100,
            'lot_purchase_cost' => 900.00,
            'lot_freight_cost' => 100.00,
        ]);

        $this->assertSame(5.0, $material->costPerSquareMeter());
    }

    #[Test]
    public function o_frete_muda_o_preco_da_caixa(): void
    {
        $semFrete = Material::factory()->create([
            'sheet_width_mm' => 1000, 'sheet_length_mm' => 1000,
            'lot_quantity' => 100, 'lot_purchase_cost' => 500.00,
            'lot_freight_cost' => null,
        ]);

        $comFrete = Material::factory()->create([
            'sheet_width_mm' => 1000, 'sheet_length_mm' => 1000,
            'lot_quantity' => 100, 'lot_purchase_cost' => 500.00,
            'lot_freight_cost' => 200.00,
        ]);

        // 40% a mais de custo de material — a diferença que antes desaparecia
        // entre a nota do fornecedor e o preço da caixa.
        $this->assertSame(5.0, $semFrete->costPerSquareMeter());
        $this->assertSame(7.0, $comFrete->costPerSquareMeter());
    }

    #[Test]
    public function frete_ausente_e_frete_zero_e_nao_cadastro_incompleto(): void
    {
        // Retirada no fornecedor é caso real. Exigir um zero digitado só
        // acrescentaria um campo obrigatório sem informação nenhuma.
        $material = Material::factory()->create([
            'sheet_width_mm' => 500, 'sheet_length_mm' => 1000,
            'lot_quantity' => 10, 'lot_purchase_cost' => 100.00,
            'lot_freight_cost' => null,
        ]);

        $this->assertSame(20.0, $material->costPerSquareMeter());
    }

    #[Test]
    public function a_ferragem_tambem_rateia_o_frete(): void
    {
        /*
         * Uma caixa de mil ímãs com R$ 60 de entrega custa mais do que a nota
         * diz. Aqui não há medida de folha: o item já É a unidade cobrada.
         *
         *   (140 + 60) ÷ 1000 = R$ 0,20 por ímã
         */
        $ima = Material::factory()->create([
            'type' => MaterialType::Hardware,
            'cost_unit' => MaterialUnit::Piece,
            'cost_per_unit' => 0.14,
            'lot_quantity' => 1000,
            'lot_purchase_cost' => 140.00,
            'lot_freight_cost' => 60.00,
        ]);

        $this->assertSame(0.2, $ima->costPerPiece());
    }

    /* ── Convivência com o cadastro antigo ─────────────────────────────── */

    #[Test]
    public function material_sem_lote_continua_calculando_como_antes(): void
    {
        $porArea = Material::factory()->create([
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 3.20,
        ]);

        $porQuilo = Material::factory()->create([
            'cost_unit' => MaterialUnit::Kilogram,
            'cost_per_unit' => 8.50,
            'grammage_kg_per_m2' => 0.300,
        ]);

        // Sem esta convivência, a mudança obrigaria a recadastrar o estoque
        // inteiro antes de o sistema voltar a orçar.
        $this->assertSame(3.20, $porArea->costPerSquareMeter());
        $this->assertSame(2.55, round($porQuilo->costPerSquareMeter(), 2));
    }

    #[Test]
    public function lote_incompleto_nao_assume_o_calculo(): void
    {
        // Valor do lote sem a medida da folha: não há como fechar área, então o
        // caminho antigo continua valendo em vez de o sistema inventar um número.
        $semFolha = Material::factory()->create([
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 3.20,
            'lot_quantity' => 100,
            'lot_purchase_cost' => 900.00,
        ]);

        $this->assertSame(3.20, $semFolha->costPerSquareMeter());

        // Medida da folha sem valor do lote: idem.
        $semValor = Material::factory()->create([
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 3.20,
            'sheet_width_mm' => 1000,
            'sheet_length_mm' => 1000,
        ]);

        $this->assertSame(3.20, $semValor->costPerSquareMeter());
    }

    #[Test]
    public function lote_de_zero_itens_nao_estoura_a_divisao(): void
    {
        /*
         * A Request barra na entrada, mas um seeder ou uma importação poderiam
         * chegar assim — e uma divisão por zero aqui derrubaria o cálculo de
         * qualquer orçamento que usasse o material.
         */
        $material = Material::factory()->create([
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 3.20,
            'sheet_width_mm' => 1000, 'sheet_length_mm' => 1000,
            'lot_quantity' => 0,
            'lot_purchase_cost' => 900.00,
        ]);

        $this->assertNull($material->lotUnitCost());
        $this->assertSame(3.20, $material->costPerSquareMeter());
    }

    /* ── Cadastro ──────────────────────────────────────────────────────── */

    #[Test]
    public function o_admin_cadastra_o_material_pelo_lote(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/materials', [
                'name' => 'Papelão cinza 2mm',
                'type' => 'cardboard',
                'cost_unit' => 'm2',
                'cost_per_unit' => 5.00,
                'sheet_width_mm' => 1000,
                'sheet_length_mm' => 2000,
                'lot_quantity' => 100,
                'lot_purchase_cost' => 900.00,
                'lot_freight_cost' => 100.00,
            ])
            ->assertCreated()
            // O R$/m² exposto é o derivado do lote, não o `cost_per_unit` digitado.
            // (float) via closure: o JSON serializa 5.0 como 5.
            ->assertJsonPath('data.cost_per_m2', fn ($v) => (float) $v === 5.0);
    }

    #[Test]
    public function as_duas_medidas_da_folha_andam_juntas(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/materials', [
                'name' => 'Meia folha',
                'type' => 'cardboard',
                'cost_unit' => 'm2',
                'cost_per_unit' => 5.00,
                'sheet_width_mm' => 1000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sheet_length_mm');
    }

    #[Test]
    public function o_valor_do_lote_exige_a_quantidade(): void
    {
        // Sem quantidade não há rateio: o valor da nota sozinho não diz quanto
        // custa uma folha.
        $this->actingAs($this->admin)
            ->postJson('/api/admin/materials', [
                'name' => 'Lote sem contagem',
                'type' => 'cardboard',
                'cost_unit' => 'm2',
                'cost_per_unit' => 5.00,
                'lot_purchase_cost' => 900.00,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lot_quantity');
    }

    #[Test]
    public function lote_de_zero_itens_e_recusado_na_entrada(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/materials', [
                'name' => 'Lote vazio',
                'type' => 'cardboard',
                'cost_unit' => 'm2',
                'cost_per_unit' => 5.00,
                'lot_quantity' => 0,
                'lot_purchase_cost' => 900.00,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lot_quantity');
    }

    /* ── Efeito no orçamento ───────────────────────────────────────────── */

    #[Test]
    public function o_orcamento_usa_o_custo_do_lote_e_o_congela_no_snapshot(): void
    {
        $material = Material::factory()->create([
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 999.99,
            'default_waste_percent' => 0.0,
            'thickness_mm' => 0.0,
            'sheet_width_mm' => 1000, 'sheet_length_mm' => 2000,
            'lot_quantity' => 100,
            'lot_purchase_cost' => 900.00,
            'lot_freight_cost' => 100.00,
        ]);

        $resposta = $this->actingAs($this->admin)
            ->postJson('/api/quotes', [
                'material_id' => $material->id,
                'box_model' => 'free',
                'width_mm' => 300, 'height_mm' => 200, 'depth_mm' => 150,
                'quantity' => 1,
                'waste_percent' => 0,
                'production_minutes_per_unit' => 0,
                'profit_margin_percent' => 0,
                'client_name' => 'Ana',
                'custom_parts' => [[
                    'material_id' => $material->id,
                    'name' => 'Chapa',
                    'role' => 'structure',
                    'width_mm' => 1000, 'length_mm' => 1000, 'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        // 1m² a R$ 5,00/m², sem perda: o custo do lote atravessou a cadeia
        // inteira, e o `cost_per_unit` de R$ 999,99 nunca foi consultado.
        $this->assertSame(5.0, (float) $resposta->json('data.costs.material'));

        /*
         * E fica congelado. Renegociar o frete amanhã não pode reescrever o
         * preço que o cliente aprovou hoje — mesma promessa do resto do
         * snapshot.
         */
        $quote = Quote::latest('id')->first();
        $this->assertSame(5.0, (float) $quote->pricing_snapshot['material_cost_per_m2']);
    }

    /* ── Frações do preço ──────────────────────────────────────────────── */

    #[Test]
    public function o_resultado_informa_quanto_do_preco_e_insumo_e_quanto_e_trabalho(): void
    {
        $material = Material::factory()->create([
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 10.00,
            'default_waste_percent' => 0.0,
            'thickness_mm' => 0.0,
        ]);

        $resposta = $this->actingAs($this->admin)
            ->postJson('/api/quotes/simulate', [
                'material_id' => $material->id,
                'box_model' => 'free',
                'width_mm' => 300, 'height_mm' => 200, 'depth_mm' => 150,
                'quantity' => 1,
                'waste_percent' => 0,
                'production_minutes_per_unit' => 0,
                'profit_margin_percent' => 0,
                'custom_parts' => [[
                    'material_id' => $material->id,
                    'name' => 'Chapa',
                    'role' => 'structure',
                    'width_mm' => 1000, 'length_mm' => 1000, 'quantity' => 1,
                ]],
            ])
            ->assertOk();

        /*
         * Sem tempo de produção e sem margem: o preço é só o material, então a
         * fração de insumo é 100% e a de trabalho é zero.
         *
         * A fração é sobre o PREÇO e não sobre o custo — sobre o custo as duas
         * somariam sempre 100% e não responderiam a pergunta que importa:
         * "estou vendendo papelão ou vendendo trabalho?".
         */
        $this->assertSame(100.0, (float) $resposta->json('data.material_share_percent'));
        $this->assertSame(0.0, (float) $resposta->json('data.labor_share_percent'));
    }

    #[Test]
    public function as_fracoes_nao_estouram_com_preco_zero(): void
    {
        // Material de graça, tempo zero, margem zero: preço zero. É um caminho
        // que a suíte de paridade exercita, e uma divisão por zero aqui
        // derrubaria o cálculo por causa de um número só informativo.
        $material = Material::factory()->create([
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 0.0001,
            'default_waste_percent' => 0.0,
            'thickness_mm' => 0.0,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/quotes/simulate', [
                'material_id' => $material->id,
                'box_model' => 'free',
                'width_mm' => 10, 'height_mm' => 10, 'depth_mm' => 10,
                'quantity' => 1,
                'waste_percent' => 0,
                'production_minutes_per_unit' => 0,
                'profit_margin_percent' => 0,
                'custom_parts' => [[
                    'material_id' => $material->id,
                    'name' => 'Mínima',
                    'role' => 'structure',
                    'width_mm' => 1, 'length_mm' => 1, 'quantity' => 1,
                ]],
            ])
            ->assertOk();
    }
}

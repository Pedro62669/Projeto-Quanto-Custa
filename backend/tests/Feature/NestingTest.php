<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GrainDirection;
use App\Enums\MaterialUnit;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Models\User;
use App\Services\Production\NestingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plano de corte 2D — a perda que o sistema até agora só chutava.
 *
 * `default_waste_percent` é um número digitado uma vez no cadastro. O plano de
 * corte mede o que sobra de verdade no arranjo das peças na folha, e a diferença
 * entre os dois é dinheiro que sai do lucro sem aparecer em lugar nenhum.
 *
 * O tema recorrente destes testes é que ele é INFORMATIVO: não toca no preço,
 * não tem gêmeo em TypeScript e não entra na paridade. Nesting é heurística, e
 * um preço que depende de heurística muda sem nenhuma entrada ter mudado.
 */
class NestingTest extends TestCase
{
    use RefreshDatabase;

    private NestingCalculator $nesting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nesting = new NestingCalculator;
    }

    /** @return list<array<string, mixed>> */
    private function peca(string $nome, float $w, float $l, int $qtd): array
    {
        return [['name' => $nome, 'width_mm' => $w, 'length_mm' => $l, 'quantity' => $qtd]];
    }

    /* ── A conta ───────────────────────────────────────────────────────── */

    #[Test]
    public function quatro_pecas_exatas_cabem_numa_folha_sem_a_lamina(): void
    {
        /*
         * Folha 1000×1000, quatro peças de 500×500, lâmina zero: encaixe
         * perfeito, 100% de aproveitamento. É o caso de referência — se ele
         * falhar, a subdivisão da árvore está errada e nada mais importa.
         */
        $plano = $this->nesting->plan(
            parts: $this->peca('Quadrante', 500, 500, 4),
            sheetWidthMm: 1000, sheetLengthMm: 1000,
            allowRotation: false, kerfMm: 0.0,
        );

        $this->assertSame(1, $plano['sheets_needed']);
        $this->assertSame(100.0, $plano['efficiency_percent']);
        $this->assertSame(0.0, $plano['waste_percent']);
        $this->assertCount(4, $plano['layouts'][0]['parts']);
    }

    #[Test]
    public function a_lamina_consome_material_e_reduz_o_aproveitamento(): void
    {
        // As mesmas quatro peças, agora com lâmina de 1,5mm: não cabem mais
        // quatro na folha. O corte come material, e ignorá-lo faria o plano
        // prometer um aproveitamento que a bancada não entrega.
        $plano = $this->nesting->plan(
            parts: $this->peca('Quadrante', 500, 500, 4),
            sheetWidthMm: 1000, sheetLengthMm: 1000,
            allowRotation: false, kerfMm: 1.5,
        );

        $this->assertGreaterThan(1, $plano['sheets_needed']);
    }

    #[Test]
    public function a_perda_real_aparece_onde_o_percentual_cadastrado_nao_via(): void
    {
        /*
         * Peça de 600×600 numa folha de 1000×1000: cabe uma só, e sobram 64%.
         * Nenhum `default_waste_percent` de 12% descreve isso — e é exatamente
         * essa cegueira que o módulo existe para acabar.
         */
        $plano = $this->nesting->plan(
            parts: $this->peca('Tampa grande', 600, 600, 1),
            sheetWidthMm: 1000, sheetLengthMm: 1000,
            allowRotation: false, kerfMm: 0.0,
        );

        $this->assertSame(1, $plano['sheets_needed']);
        $this->assertSame(36.0, $plano['efficiency_percent']);
        $this->assertSame(64.0, $plano['waste_percent']);
    }

    /* ── Fibra ─────────────────────────────────────────────────────────── */

    #[Test]
    public function a_rotacao_encaixa_o_que_nao_caberia_de_pe(): void
    {
        // 900×200 não cabe deitada numa folha 300×1000, mas cabe girada.
        $comRotacao = $this->nesting->plan(
            parts: $this->peca('Lombada', 900, 200, 1),
            sheetWidthMm: 300, sheetLengthMm: 1000,
            allowRotation: true, kerfMm: 0.0,
        );

        $this->assertTrue($comRotacao['layouts'][0]['parts'][0]['rotated']);
    }

    #[Test]
    public function material_com_fibra_nao_gira_e_a_peca_deixa_de_caber(): void
    {
        /*
         * A mesma peça, sem permissão de girar. O sistema erra para o lado da
         * caixa: economizar chapa cortando atravessado produz tampa que empena
         * dias depois da entrega, quando já não há conserto.
         */
        $this->expectException(\DomainException::class);

        $this->nesting->plan(
            parts: $this->peca('Lombada', 900, 200, 1),
            sheetWidthMm: 300, sheetLengthMm: 1000,
            allowRotation: false, kerfMm: 0.0,
        );
    }

    #[Test]
    public function so_o_material_sem_fibra_permite_rotacao(): void
    {
        $this->assertTrue(GrainDirection::None->permiteRotacao());
        $this->assertFalse(GrainDirection::Length->permiteRotacao());
        $this->assertFalse(GrainDirection::Width->permiteRotacao());
    }

    /* ── Os defeitos corrigidos do algoritmo original ──────────────────── */

    #[Test]
    public function o_resultado_e_reproduzivel_com_pecas_de_mesma_area(): void
    {
        /*
         * Duas peças de área idêntica e formatos diferentes. Ordenar só por área
         * deixaria a ordem indefinida, e a ordem decide o layout, que decide a
         * perda: o mesmo orçamento produziria relatórios diferentes em execuções
         * diferentes. Um número que muda sozinho não serve para conferir nada.
         */
        $pecas = [
            ['name' => 'Deitada', 'width_mm' => 400.0, 'length_mm' => 100.0, 'quantity' => 3],
            ['name' => 'Em pé', 'width_mm' => 100.0, 'length_mm' => 400.0, 'quantity' => 3],
            ['name' => 'Quadrada', 'width_mm' => 200.0, 'length_mm' => 200.0, 'quantity' => 3],
        ];

        $primeiro = $this->nesting->plan($pecas, 1000, 1000, false, 0.0);

        // Mesma entrada em ordem trocada: o desempate total precisa produzir o
        // mesmo arranjo.
        $segundo = $this->nesting->plan(
            [$pecas[2], $pecas[0], $pecas[1]], 1000, 1000, false, 0.0,
        );

        $this->assertSame($primeiro['waste_percent'], $segundo['waste_percent']);
        $this->assertEquals($primeiro['layouts'], $segundo['layouts']);
    }

    #[Test]
    public function peca_que_ocupa_a_folha_inteira_nao_gera_sobra_negativa(): void
    {
        /*
         * A peça cobre a folha e a lâmina come mais do que sobra: sem o piso em
         * zero, nasceriam nós de dimensão negativa. Eles nunca aceitam peça, mas
         * fazem a escolha do corte primário comparar dois números negativos.
         */
        $plano = $this->nesting->plan(
            parts: $this->peca('Folha inteira', 1000, 1000, 2),
            sheetWidthMm: 1000, sheetLengthMm: 1000,
            allowRotation: false, kerfMm: 1.5,
        );

        $this->assertSame(2, $plano['sheets_needed']);
        $this->assertSame(100.0, $plano['efficiency_percent']);
    }

    #[Test]
    public function peca_maior_que_a_folha_e_recusada_com_as_duas_medidas(): void
    {
        // Cadastro incoerente, não erro de programação: o usuário precisa ver
        // qual peça e qual folha para corrigir uma das duas.
        try {
            $this->nesting->plan(
                parts: $this->peca('Chapa gigante', 2000, 500, 1),
                sheetWidthMm: 1000, sheetLengthMm: 1000,
                allowRotation: false, kerfMm: 0.0,
            );

            $this->fail('Deveria ter recusado a peça maior que a folha.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Chapa gigante', $e->getMessage());
            $this->assertStringContainsString('2000', $e->getMessage());
            $this->assertStringContainsString('1000', $e->getMessage());
        }
    }

    #[Test]
    public function um_lote_enorme_e_truncado_em_vez_de_travar_o_servidor(): void
    {
        /*
         * O modelo livre aceita 60 peças de até 500 unidades. Sem teto, o laço
         * percorre a árvore de todas as chapas abertas a cada peça — dezenas de
         * milhões de visitas a nó dentro de uma requisição HTTP.
         */
        $plano = $this->nesting->plan(
            parts: $this->peca('Miúda', 50, 50, 30000),
            sheetWidthMm: 1000, sheetLengthMm: 1000,
            allowRotation: false, kerfMm: 0.0,
        );

        $this->assertTrue($plano['truncated']);
        $this->assertSame(30000, $plano['pieces_total']);
        $this->assertLessThan(30000, $plano['pieces_planned']);

        // E a extrapolação continua útil: o aproveitamento se estabiliza, então
        // a estimativa de folhas para o pedido inteiro é honesta.
        $this->assertGreaterThan($plano['sheets_needed'], $plano['sheets_estimated']);
    }

    /* ── Na ficha técnica ──────────────────────────────────────────────── */

    #[Test]
    public function a_ficha_compara_a_perda_orcada_com_a_real(): void
    {
        CostSetting::factory()->create();
        $usuario = User::factory()->create();

        $material = Material::factory()->create([
            'name' => 'Papelão cinza',
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 5.00,
            'default_waste_percent' => 12.0,
            'thickness_mm' => 0.0,
            'sheet_width_mm' => 1000,
            'sheet_length_mm' => 1000,
            'grain_direction' => GrainDirection::None,
        ]);

        $this->actingAs($usuario)->postJson('/api/quotes', [
            'material_id' => $material->id,
            'box_model' => 'free',
            'width_mm' => 300, 'height_mm' => 200, 'depth_mm' => 150,
            'quantity' => 1,
            'production_minutes_per_unit' => 0,
            'profit_margin_percent' => 0,
            'client_name' => 'Ana',
            'custom_parts' => [[
                'material_id' => $material->id,
                'name' => 'Tampa',
                'role' => 'structure',
                'width_mm' => 600, 'length_mm' => 600, 'quantity' => 1,
            ]],
        ])->assertCreated();

        $quote = Quote::latest('id')->first();

        $plano = $this->actingAs($usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->assertOk()
            ->json('data.cutting_plan.by_material.0');

        /*
         * O número que paga o módulo: 12% orçados contra 64% reais. Cinquenta e
         * dois pontos de diferença saindo do lucro sem aparecer em lugar nenhum.
         */
        $this->assertSame(12.0, (float) $plano['quoted_waste_percent']);
        $this->assertSame(64.0, (float) $plano['real_waste_percent']);
        $this->assertSame(52.0, (float) $plano['divergence_percent']);
    }

    #[Test]
    public function sem_medida_de_folha_a_ficha_avisa_o_que_falta_cadastrar(): void
    {
        CostSetting::factory()->create();
        $usuario = User::factory()->create();

        // Material sem folha cadastrada: o aviso é mais útil que a ausência —
        // ele diz exatamente qual cadastro completar.
        $material = Material::factory()->create([
            'name' => 'Papelão sem folha',
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 5.00,
            'thickness_mm' => 0.0,
            'sheet_width_mm' => null,
            'sheet_length_mm' => null,
        ]);

        $this->actingAs($usuario)->postJson('/api/quotes', [
            'material_id' => $material->id,
            'box_model' => 'rsc',
            'width_mm' => 300, 'height_mm' => 200, 'depth_mm' => 150,
            'quantity' => 10,
            'production_minutes_per_unit' => 0,
            'profit_margin_percent' => 0,
            'client_name' => 'Ana',
        ])->assertCreated();

        $quote = Quote::latest('id')->first();

        $resposta = $this->actingAs($usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->assertOk();

        $this->assertSame([], $resposta->json('data.cutting_plan.by_material'));

        $avisos = $resposta->json('data.cutting_plan.warnings');
        $this->assertTrue(
            collect($avisos)->contains(fn ($a) => str_contains($a, 'Papelão sem folha')),
        );
    }

    #[Test]
    public function o_plano_diz_que_nao_altera_o_preco(): void
    {
        CostSetting::factory()->create();
        $usuario = User::factory()->create();
        $material = Material::factory()->create(['thickness_mm' => 0.0]);

        $this->actingAs($usuario)->postJson('/api/quotes', [
            'material_id' => $material->id,
            'box_model' => 'rsc',
            'width_mm' => 300, 'height_mm' => 200, 'depth_mm' => 150,
            'quantity' => 10,
            'production_minutes_per_unit' => 0,
            'profit_margin_percent' => 0,
            'client_name' => 'Ana',
        ])->assertCreated();

        $quote = Quote::latest('id')->first();

        $notas = $this->actingAs($usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->assertOk()
            ->json('data.cutting_plan.notes');

        /*
         * A fronteira dita na cara de quem lê. Um plano de corte que se
         * apresenta como autoridade sobre o preço convidaria alguém a
         * renegociar com base num arranjo que a bancada pode melhorar.
         */
        $this->assertTrue(
            collect($notas)->contains(fn ($n) => str_contains($n, 'NÃO altera o preço')),
        );
    }
}

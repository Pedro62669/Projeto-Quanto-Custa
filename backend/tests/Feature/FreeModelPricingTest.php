<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Models\QuoteCustomPart;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Modelo livre — a caixa que não cabe em equação nenhuma.
 *
 * É o único caminho do motor que não passa pelo BlankCalculator: em vez de
 * derivar a planificação de largura, altura e profundidade, ele soma os
 * retângulos que o usuário mediu.
 *
 * O que estes testes protegem é a diferença entre "o preço saiu" e "o preço
 * descreve o que vai ser cortado". Antes do modelo livre, uma peça fora do
 * catálogo obrigava a escolher o modelo mais parecido e aceitar uma área que
 * não correspondia a nada — e o número saía plausível, que é o pior tipo de
 * erro.
 */
class FreeModelPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Material $cinza;

    private Material $revestimento;

    protected function setUp(): void
    {
        parent::setUp();

        CostSetting::factory()->create();

        $this->usuario = User::factory()->create();

        $this->cinza = Material::factory()->create([
            'name' => 'Papelão cinza 1,9mm',
            'type' => MaterialType::Cardboard,
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 5.00,
            'default_waste_percent' => 10.0,
            'thickness_mm' => 1.9,
        ]);

        $this->revestimento = Material::factory()->create([
            'name' => 'Papel color plus',
            'type' => MaterialType::Paper,
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 20.00,
            'default_waste_percent' => 20.0,
            'thickness_mm' => 0.3,
        ]);
    }

    /** @return array<string, mixed> */
    private function spec(array $overrides = []): array
    {
        return [
            'material_id' => $this->cinza->id,
            'box_model' => 'free',
            'width_mm' => 300,
            'height_mm' => 200,
            'depth_mm' => 150,
            'quantity' => 100,
            'production_minutes_per_unit' => 0,
            'profit_margin_percent' => 0,
            'pricing_mode' => 'markup',
            'custom_parts' => [
                [
                    'material_id' => $this->cinza->id,
                    'name' => 'Fundo',
                    'role' => 'structure',
                    'width_mm' => 1000,
                    'length_mm' => 1000,
                    'quantity' => 1,
                ],
            ],
            ...$overrides,
        ];
    }

    /* ── A soma ────────────────────────────────────────────────────────── */

    #[Test]
    public function o_custo_e_a_soma_das_pecas_medidas(): void
    {
        /*
         * Peça de 1m², perda de 10%, R$ 5,00/m² → R$ 5,50.
         *
         * Números redondos de propósito: o valor esperado é conferível de
         * cabeça, e um teste cujo resultado só o próprio código sabe calcular
         * não prova nada.
         */
        $resposta = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())
            ->assertOk();

        $this->assertSame(5.5, $resposta->json('data.material_cost'));
        // (float) porque o JSON serializa 1.0 como 1 — inteiro do lado de cá.
        $this->assertSame(1.0, (float) $resposta->json('data.area_m2_per_unit'));
    }

    #[Test]
    public function o_blank_calculator_nao_e_consultado(): void
    {
        // Sem planificação não há blank: zero é a resposta honesta, e é o que a
        // ficha técnica lê para listar as peças em vez de desenhar uma chapa.
        $resposta = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())
            ->assertOk();

        $this->assertSame(0, $resposta->json('data.blank_width_mm'));
        $this->assertSame(0, $resposta->json('data.blank_height_mm'));
        $this->assertNull($resposta->json('data.lid_width_mm'));
    }

    #[Test]
    public function as_dimensoes_da_caixa_nao_afetam_o_preco(): void
    {
        $pequena = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())
            ->assertOk()
            ->json('data.material_cost');

        /*
         * Largura, altura e profundidade continuam sendo gravadas — elas
         * descrevem a caixa para o cliente —, mas NÃO entram na conta. Se
         * entrassem, o modelo livre estaria cobrando duas vezes: a geometria
         * derivada mais os retângulos medidos.
         */
        $enorme = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'width_mm' => 2000, 'height_mm' => 2000, 'depth_mm' => 2000,
            ]))
            ->assertOk()
            ->json('data.material_cost');

        $this->assertSame($pequena, $enorme);
    }

    #[Test]
    public function cada_peca_usa_a_perda_do_proprio_material(): void
    {
        /*
         * O ponto do modelo livre. Cinza perde 10%, papel perde 20% — e o
         * `waste_percent` do orçamento (45%) precisa ser IGNORADO, senão os dois
         * materiais seriam tratados igual.
         *
         * Cinza:  1m² × 1,10 × R$ 5,00  = R$ 5,50
         * Papel:  1m² × 1,20 × R$ 20,00 = R$ 24,00
         */
        $resposta = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'waste_percent' => 45,
                'custom_parts' => [
                    [
                        'material_id' => $this->cinza->id,
                        'name' => 'Fundo',
                        'role' => 'structure',
                        'width_mm' => 1000, 'length_mm' => 1000, 'quantity' => 1,
                    ],
                    [
                        'material_id' => $this->revestimento->id,
                        'name' => 'Capa',
                        'role' => 'wrap',
                        'width_mm' => 1000, 'length_mm' => 1000, 'quantity' => 1,
                    ],
                ],
            ]))
            ->assertOk();

        $this->assertSame(5.5, $resposta->json('data.material_cost'));
        $this->assertSame(24.0, (float) $resposta->json('data.wrap_cost'));
    }

    #[Test]
    public function estrutura_e_revestimento_sao_linhas_separadas(): void
    {
        $resposta = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'custom_parts' => [
                    [
                        'material_id' => $this->revestimento->id,
                        'name' => 'Capa externa',
                        'role' => 'wrap',
                        'width_mm' => 500, 'length_mm' => 400, 'quantity' => 1,
                    ],
                ],
            ]))
            ->assertOk();

        // Só revestimento: a linha de estrutura fica zerada em vez de somar
        // tudo num número só, que esconderia qual material puxou o custo.
        $this->assertSame(0, $resposta->json('data.material_cost'));
        $this->assertGreaterThan(0, $resposta->json('data.wrap_cost'));
    }

    #[Test]
    public function a_quantidade_da_peca_e_por_caixa(): void
    {
        $uma = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())
            ->assertOk()
            ->json('data.material_cost');

        $quatro = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'custom_parts' => [[
                    'material_id' => $this->cinza->id,
                    'name' => 'Lateral',
                    'role' => 'structure',
                    'width_mm' => 1000, 'length_mm' => 1000, 'quantity' => 4,
                ]],
            ]))
            ->assertOk()
            ->json('data.material_cost');

        // "4 laterais" são 4 POR CAIXA. O custo unitário quadruplica; o lote
        // multiplica depois, como em todo o resto do sistema.
        $this->assertSame(round($uma * 4, 4), (float) $quatro);
    }

    /* ── Validação ─────────────────────────────────────────────────────── */

    #[Test]
    public function o_modelo_livre_exige_ao_menos_uma_peca(): void
    {
        /*
         * Sem peça, o orçamento sairia só com mão de obra — um preço que parece
         * calculado e não descreve nada.
         */
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec(['custom_parts' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_parts');

        $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', collect($this->spec())->except('custom_parts')->all())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_parts');
    }

    #[Test]
    public function ferragem_e_berco_nao_entram_como_peca(): void
    {
        // Ímã é contado, não medido, e berço tem parâmetros de construção
        // próprios. Aceitá-los aqui criaria um segundo caminho para o mesmo
        // custo — e os dois divergiriam.
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'custom_parts' => [[
                    'material_id' => $this->cinza->id,
                    'name' => 'Ímã',
                    'role' => 'hardware',
                    'width_mm' => 10, 'length_mm' => 10, 'quantity' => 4,
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_parts.0.role');
    }

    #[Test]
    public function as_pecas_sao_ignoradas_nos_modelos_com_geometria(): void
    {
        $comPecas = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'box_model' => 'rsc',
                'waste_percent' => 10,
            ]))
            ->assertOk()
            ->json('data.material_cost');

        $semPecas = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', collect($this->spec([
                'box_model' => 'rsc',
                'waste_percent' => 10,
            ]))->except('custom_parts')->all())
            ->assertOk()
            ->json('data.material_cost');

        /*
         * Um RSC que somasse a planificação calculada MAIS os retângulos
         * digitados cobraria o material duas vezes — e o número sairia
         * plausível.
         */
        $this->assertSame($semPecas, $comPecas);
    }

    #[Test]
    public function uma_peca_nao_alcanca_o_material_de_outra_empresa(): void
    {
        $vizinha = Tenant::factory()->create();
        $materialAlheio = Material::factory()->create(['tenant_id' => $vizinha->id]);

        // O TenantScope não encontra o material: 404, e não um orçamento
        // precificado com o custo do concorrente.
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'custom_parts' => [[
                    'material_id' => $materialAlheio->id,
                    'name' => 'Peça alheia',
                    'role' => 'structure',
                    'width_mm' => 500, 'length_mm' => 500, 'quantity' => 1,
                ]],
            ]))
            ->assertNotFound();
    }

    /* ── Persistência ──────────────────────────────────────────────────── */

    #[Test]
    public function salvar_grava_as_pecas_e_as_congela_no_snapshot(): void
    {
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->spec(['client_name' => 'Ana']))
            ->assertCreated();

        $quote = Quote::latest('id')->first();

        // Linhas próprias: é a tabela que a ficha consulta e que o usuário
        // edita ao duplicar o orçamento.
        $this->assertSame(1, $quote->customParts()->count());

        $peca = $quote->customParts()->sole();
        $this->assertSame('Fundo', $peca->name);
        $this->assertSame(1000, $peca->width_mm);
        $this->assertSame($quote->tenant_id, $peca->tenant_id);

        // E a fotografia: se a medida mudar amanhã, o que foi aprovado ontem
        // não pode mudar de preço.
        $this->assertSame('Fundo', $quote->pricing_snapshot['custom_parts'][0]['name']);
        $this->assertSame(5.0, (float) $quote->pricing_snapshot['custom_parts'][0]['cost_per_m2']);
    }

    #[Test]
    public function editar_a_peca_nao_altera_o_orcamento_ja_fechado(): void
    {
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->spec(['client_name' => 'Ana']))
            ->assertCreated();

        $quote = Quote::latest('id')->first();
        $precoOriginal = $quote->total_price;

        $quote->customParts()->sole()->update(['width_mm' => 3000]);

        // O snapshot é o que foi combinado; a linha editável é material de
        // trabalho. Confundir os dois faria a proposta assinada mudar sozinha.
        $this->assertSame($precoOriginal, $quote->fresh()->total_price);
        $this->assertSame(1000, $quote->fresh()->pricing_snapshot['custom_parts'][0]['width_mm']);
    }

    #[Test]
    public function apagar_o_orcamento_leva_as_pecas_junto(): void
    {
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->spec(['client_name' => 'Ana']))
            ->assertCreated();

        $quote = Quote::latest('id')->first();

        $quote->forceDelete();

        $this->assertSame(
            0,
            QuoteCustomPart::query()->withoutGlobalScope(TenantScope::class)->count(),
        );
    }

    /* ── Ficha técnica ─────────────────────────────────────────────────── */

    #[Test]
    public function a_ficha_tecnica_lista_as_pecas_em_vez_de_derivar(): void
    {
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->spec([
                'client_name' => 'Ana',
                'custom_parts' => [
                    [
                        'material_id' => $this->cinza->id,
                        'name' => 'Fundo',
                        'role' => 'structure',
                        'width_mm' => 300, 'length_mm' => 200, 'quantity' => 1,
                    ],
                    [
                        'material_id' => $this->revestimento->id,
                        'name' => 'Capa',
                        'role' => 'wrap',
                        'width_mm' => 330, 'length_mm' => 230, 'quantity' => 2,
                    ],
                ],
            ]))
            ->assertCreated();

        $quote = Quote::latest('id')->first();

        $resposta = $this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->assertOk();

        $this->assertSame('Fundo', $resposta->json('data.cut_template.structure.0.name'));
        $this->assertSame(300, $resposta->json('data.cut_template.structure.0.width_mm'));

        $this->assertSame('Capa', $resposta->json('data.cut_template.wrap.0.name'));
        $this->assertSame(2, $resposta->json('data.cut_template.wrap.0.quantity'));

        // O material entra na linha: no modelo livre ele varia peça a peça, e
        // quem separa as pilhas na bancada precisa saber qual chapa pegar.
        $this->assertSame('Papel color plus', $resposta->json('data.cut_template.wrap.0.material'));
    }

    #[Test]
    public function a_ficha_avisa_que_a_conferencia_e_de_quem_mediu(): void
    {
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->spec(['client_name' => 'Ana']))
            ->assertCreated();

        $quote = Quote::latest('id')->first();

        $notas = $this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->assertOk()
            ->json('data.cut_template.notes');

        /*
         * Nos modelos com geometria o sistema garante que a peça cortada é a
         * peça vendida. Aqui essa garantia é de quem mediu — e dizê-lo é mais
         * honesto do que deixar a ficha parecer conferida.
         */
        $this->assertTrue(
            collect($notas)->contains(fn ($n) => str_contains($n, 'não valida')),
            'A ficha do modelo livre precisa dizer que o sistema não confere as medidas.',
        );
    }
}

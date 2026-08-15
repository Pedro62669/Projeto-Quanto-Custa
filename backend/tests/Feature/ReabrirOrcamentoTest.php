<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CradleType;
use App\Enums\MaterialUnit;
use App\Enums\QuoteStatus;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reabrir um orçamento: duplicar sempre, editar só rascunho.
 *
 * Nada disso era possível porque a especificação não voltava inteira — o
 * recurso devolvia medidas e modelo, sem tampa, sem lista de materiais e sem
 * berço. Metade do que fazia a caixa ser aquela caixa ficava no banco e não
 * saía por lugar nenhum.
 *
 * A regra que o controller já registrava continua valendo onde sempre valeu:
 * orçamento ENVIADO ao cliente é imutável. Rascunho é o caso que ela nunca
 * cobriu — não foi enviado a ninguém, e mandar refazer tudo por causa de uma
 * medida errada era punição sem beneficiário.
 */
class ReabrirOrcamentoTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Material $papelao;

    protected function setUp(): void
    {
        parent::setUp();

        CostSetting::factory()->create();
        $this->usuario = User::factory()->create();
        $this->papelao = Material::factory()->create([
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 5.00,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function payload(array $extra = []): array
    {
        return [
            'material_id' => $this->papelao->id,
            'box_model' => 'rigid_magnet',
            'width_mm' => 300, 'height_mm' => 100, 'depth_mm' => 200,
            'quantity' => 250,
            'production_minutes_per_unit' => 0,
            'profit_margin_percent' => 0,
            'client_name' => 'Ana',
            ...$extra,
        ];
    }

    private function criar(array $extra = []): Quote
    {
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->payload($extra))
            ->assertCreated();

        return Quote::latest('id')->firstOrFail();
    }

    /* ── A especificação volta inteira ─────────────────────────────────── */

    #[Test]
    public function o_show_devolve_o_que_a_api_aceitaria_de_volta(): void
    {
        $ima = Material::factory()->hardware(0.85)->create();
        $papel = Material::factory()->create([
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 12.00,
        ]);

        $quote = $this->criar([
            'lid_height_mm' => 40,
            'components' => [
                ['material_id' => $papel->id, 'role' => 'wrap'],
                ['material_id' => $ima->id, 'role' => 'hardware', 'quantity' => 4],
            ],
        ]);

        $spec = $this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame($this->papelao->id, $spec['specification']['material_id']);
        $this->assertSame(40.0, (float) $spec['specification']['lid_height_mm']);

        /*
         * O revestimento volta como LINHA da lista, apesar de morar em coluna
         * própria: quem consome esta chave é o formulário, e para ele
         * revestimento é um item como ímã. A assimetria de armazenamento existe
         * por cardinalidade e não precisa vazar para quem só quer reabrir.
         */
        $papeis = array_column($spec['components'], 'role');
        $this->assertContains('wrap', $papeis);
        $this->assertContains('hardware', $papeis);
        $this->assertCount(2, $spec['components']);
    }

    #[Test]
    public function o_orcamento_reaberto_produz_o_mesmo_preco(): void
    {
        /*
         * O teste que dá sentido a "duplicar": mandar de volta o que o show
         * devolveu tem de gerar um orçamento idêntico. Se um campo se perde no
         * caminho, a cópia sai com outro preço — e ninguém percebe, porque os
         * dois números são plausíveis.
         *
         * São TRÊS blocos, e a primeira versão deste teste mandava só um: sem
         * `parameters` a cópia pegou a margem padrão da empresa e saiu quase dez
         * vezes mais cara. É exatamente o erro que ele existe para pegar.
         */
        $ima = Material::factory()->hardware(0.85)->create();

        $original = $this->criar([
            'lid_height_mm' => 40,
            'components' => [['material_id' => $ima->id, 'role' => 'hardware', 'quantity' => 4]],
        ]);

        $spec = $this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$original->id}")->assertOk()->json('data');

        $this->actingAs($this->usuario)->postJson('/api/quotes', [
            ...$spec['specification'],
            ...$spec['parameters'],
            'components' => $spec['components'],
            'client_name' => 'Ana',
        ])->assertCreated();

        $copia = Quote::latest('id')->firstOrFail();

        $this->assertNotSame($original->id, $copia->id);
        $this->assertSame((float) $original->total_price, (float) $copia->total_price);
        $this->assertSame((float) $original->hardware_cost, (float) $copia->hardware_cost);
    }

    /* ── Editar o rascunho ─────────────────────────────────────────────── */

    #[Test]
    public function o_rascunho_e_recalculado_ao_ser_reeditado(): void
    {
        $quote = $this->criar();
        $precoOriginal = (float) $quote->total_price;

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}/specification", $this->payload([
                'quantity' => 500,
            ]))
            ->assertOk();

        $quote->refresh();

        $this->assertSame(500, $quote->quantity);
        $this->assertNotSame($precoOriginal, (float) $quote->total_price);
    }

    #[Test]
    public function a_referencia_e_o_autor_sobrevivem_a_edicao(): void
    {
        // A referência já foi para o papel, e quem criou continua sendo quem
        // criou. Reeditar corrige a caixa, não reescreve a autoria.
        $quote = $this->criar();
        $referencia = $quote->reference;

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}/specification", $this->payload(['quantity' => 300]))
            ->assertOk();

        $quote->refresh();

        $this->assertSame($referencia, $quote->reference);
        $this->assertSame($this->usuario->id, $quote->user_id);
        $this->assertSame(QuoteStatus::Draft, $quote->status);
    }

    #[Test]
    public function a_lista_de_materiais_e_substituida_e_nao_somada(): void
    {
        /*
         * Sem o `delete()` antes de regravar, corrigir a quantidade de ímãs de
         * 4 para 2 deixaria as duas linhas no banco e a ficha técnica mandaria
         * separar seis.
         */
        $ima = Material::factory()->hardware(0.85)->create();

        $quote = $this->criar([
            'components' => [['material_id' => $ima->id, 'role' => 'hardware', 'quantity' => 4]],
        ]);

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}/specification", $this->payload([
                'components' => [['material_id' => $ima->id, 'role' => 'hardware', 'quantity' => 2]],
            ]))
            ->assertOk();

        $quote->refresh()->load('components');

        $this->assertCount(1, $quote->components);
        $this->assertSame(2.0, $quote->components->first()->quantity);
    }

    #[Test]
    public function o_snapshot_e_refeito(): void
    {
        // O snapshot é a fotografia do que foi CALCULADO, e um rascunho
        // reeditado tem um cálculo novo. Manter o antigo faria a ficha técnica
        // cortar a caixa velha.
        $quote = $this->criar();
        $antes = $quote->pricing_snapshot['breakdown']['total_price'];

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}/specification", $this->payload(['quantity' => 500]))
            ->assertOk();

        $depois = $quote->fresh()->pricing_snapshot['breakdown']['total_price'];

        $this->assertNotEquals($antes, $depois);
    }

    /* ── O que não se edita ────────────────────────────────────────────── */

    #[Test]
    public function o_enviado_nao_pode_ser_reeditado(): void
    {
        $quote = $this->criar();

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}", ['status' => 'sent'])
            ->assertOk();

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}/specification", $this->payload(['quantity' => 999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        // A promessa que o controller sempre registrou: o que o cliente recebeu
        // não muda de valor.
        $this->assertSame(250, $quote->fresh()->quantity);
    }

    #[Test]
    public function o_aprovado_nao_pode_ser_reeditado(): void
    {
        $quote = $this->criar();

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/approve")->assertOk();

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}/specification", $this->payload(['quantity' => 999]))
            ->assertUnprocessable();

        $this->assertSame(250, $quote->fresh()->quantity);
    }

    #[Test]
    public function o_berco_sobrevive_ao_ciclo_completo(): void
    {
        $espuma = Material::factory()->create([
            'cost_unit' => MaterialUnit::CubicMeter,
            'cost_per_unit' => 800.00,
            'thickness_mm' => 10.0,
        ]);

        $quote = $this->criar([
            'components' => [['material_id' => $espuma->id, 'role' => 'cradle']],
            'cradle_type' => CradleType::Foam->value,
            'cradle_rows' => 3,
            'cradle_columns' => 4,
            'cradle_height_ratio' => 0.65,
        ]);

        $spec = $this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}")->assertOk()->json('data');

        $this->assertSame('foam', $spec['specification']['cradle_type']);
        $this->assertSame(3, $spec['specification']['cradle_rows']);
        $this->assertSame(0.65, (float) $spec['specification']['cradle_height_ratio']);
        $this->assertSame('cradle', $spec['components'][0]['role']);
    }
}

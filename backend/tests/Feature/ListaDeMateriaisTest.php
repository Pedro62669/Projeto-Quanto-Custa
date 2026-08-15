<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ComponentRole;
use App\Enums\CradleType;
use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A lista de materiais do orçamento — o que era custo e agora também é dado.
 *
 * Até esta fase o orçamento gravava `hardware_cost` e nada sobre QUAIS
 * ferragens. Uma caixa com quatro ímãs de neodímio e uma com quatro rebites
 * produziam a mesma linha no banco.
 *
 * Duas consequências que pareciam problemas separados e tinham a mesma causa: a
 * ficha técnica saía sem os ímãs, e nenhum orçamento salvo podia ser reaberto,
 * porque metade da especificação não estava em lugar nenhum.
 */
class ListaDeMateriaisTest extends TestCase
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
            'name' => 'Papelão cinza 1,9mm',
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 5.00,
            'thickness_mm' => 1.9,
        ]);
    }

    /**
     * Cria o orçamento e devolve o registro gravado.
     *
     * @param  array<string, mixed>  $extra
     */
    private function orcar(array $extra = []): Quote
    {
        $this->actingAs($this->usuario)->postJson('/api/quotes', [
            'material_id' => $this->papelao->id,
            'box_model' => 'rigid_magnet',
            'width_mm' => 300, 'height_mm' => 100, 'depth_mm' => 200,
            'quantity' => 250,
            'production_minutes_per_unit' => 0,
            'profit_margin_percent' => 0,
            'client_name' => 'Ana',
            ...$extra,
        ])->assertCreated();

        return Quote::latest('id')->firstOrFail();
    }

    private function ima(): Material
    {
        return Material::factory()->hardware(0.85)->create(['name' => 'Ímã 6×2mm']);
    }

    /* ── O que é gravado ───────────────────────────────────────────────── */

    #[Test]
    public function a_ferragem_vira_linha_com_material_e_quantidade(): void
    {
        $ima = $this->ima();

        $quote = $this->orcar(['components' => [
            ['material_id' => $ima->id, 'role' => 'hardware', 'quantity' => 4],
        ]]);

        $this->assertCount(1, $quote->components);

        $linha = $quote->components->first();
        $this->assertSame($ima->id, $linha->material_id);
        $this->assertSame(ComponentRole::Hardware, $linha->component_role);
        $this->assertSame(4.0, $linha->quantity);
    }

    #[Test]
    public function estrutura_e_revestimento_nao_viram_linha(): void
    {
        /*
         * A estrutura já é `quotes.material_id` e o revestimento tem coluna
         * própria. O frontend manda a lista COMPLETA — é como ele a exibe — e
         * gravar os quatro papéis criaria uma segunda cópia dos dois primeiros,
         * que divergiria no primeiro orçamento editado.
         */
        $papel = Material::factory()->create([
            'name' => 'Color Plus',
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 12.00,
        ]);

        $quote = $this->orcar(['components' => [
            ['material_id' => $this->papelao->id, 'role' => 'structure'],
            ['material_id' => $papel->id, 'role' => 'wrap'],
        ]]);

        $this->assertCount(0, $quote->components);

        // E o revestimento continua onde sempre esteve.
        $this->assertSame($papel->id, $quote->wrap_material_id);
    }

    #[Test]
    public function o_berco_grava_o_material_e_os_parametros_da_grade(): void
    {
        $espuma = Material::factory()->create([
            'name' => 'Espuma EVA',
            'type' => MaterialType::Other,
            'cost_unit' => MaterialUnit::CubicMeter,
            'cost_per_unit' => 800.00,
            'thickness_mm' => 10.0,
        ]);

        $quote = $this->orcar([
            'components' => [['material_id' => $espuma->id, 'role' => 'cradle']],
            'cradle_type' => CradleType::Foam->value,
            'cradle_rows' => 3,
            'cradle_columns' => 4,
            'cradle_height_ratio' => 0.65,
        ]);

        $linha = $quote->components->first();
        $this->assertSame($espuma->id, $linha->material_id);

        /*
         * Berço não se conta: a grade é que descreve o tamanho dele. Um `1`
         * gravado aqui seria um número inventado que a próxima pessoa tentaria
         * interpretar.
         */
        $this->assertNull($linha->quantity);

        // Os parâmetros de construção ficam no orçamento, não na linha: a mesma
        // espuma serve a berços de alturas diferentes.
        $this->assertSame(CradleType::Foam, $quote->cradle_type);
        $this->assertSame(3, $quote->cradle_rows);
        $this->assertSame(4, $quote->cradle_columns);
        $this->assertSame(0.65, $quote->cradle_height_ratio);
    }

    #[Test]
    public function a_fracao_da_quantidade_sobrevive(): void
    {
        // Fita de cetim é comprada por peça e consumida em metro e meio. Um
        // inteiro arredondaria para 2 e cobraria fita que ninguém usou.
        $fita = Material::factory()->hardware(3.20)->create(['name' => 'Fita de cetim']);

        $quote = $this->orcar(['components' => [
            ['material_id' => $fita->id, 'role' => 'hardware', 'quantity' => 1.5],
        ]]);

        $this->assertSame(1.5, $quote->components->first()->quantity);
    }

    /* ── Na ficha técnica ──────────────────────────────────────────────── */

    #[Test]
    public function a_ficha_tecnica_manda_separar_os_imas(): void
    {
        $ima = $this->ima();

        $quote = $this->orcar(['components' => [
            ['material_id' => $ima->id, 'role' => 'hardware', 'quantity' => 4],
        ]]);

        $lista = $this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->assertOk()
            ->json('data.picking_list');

        $ferragem = collect($lista)->firstWhere('material_role', 'hardware');

        $this->assertNotNull(
            $ferragem,
            'A ficha saiu sem a ferragem — a produção compraria ímã de memória.',
        );

        $this->assertSame('Ímã 6×2mm', $ferragem['piece']);
        $this->assertSame(4.0, (float) $ferragem['per_unit']);

        // Quem vai ao estoque leva o pedido inteiro: 4 por caixa × 250 caixas.
        $this->assertSame(1000.0, (float) $ferragem['total']);
    }

    #[Test]
    public function a_ficha_descreve_a_grade_do_berco(): void
    {
        $espuma = Material::factory()->create([
            'name' => 'Espuma EVA',
            'type' => MaterialType::Other,
            'cost_unit' => MaterialUnit::CubicMeter,
            'cost_per_unit' => 800.00,
            'thickness_mm' => 10.0,
        ]);

        $quote = $this->orcar([
            'components' => [['material_id' => $espuma->id, 'role' => 'cradle']],
            'cradle_type' => CradleType::Foam->value,
            'cradle_rows' => 3,
            'cradle_columns' => 4,
            'cradle_height_ratio' => 0.65,
        ]);

        $lista = $this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->assertOk()
            ->json('data.picking_list');

        $berco = collect($lista)->firstWhere('material_role', 'cradle');

        $this->assertNotNull($berco);
        $this->assertSame('Espuma EVA', $berco['piece']);

        // A grade, e não "unidade": é o que a bancada precisa para montar.
        $this->assertStringContainsString('grade 3 × 4', $berco['size']);
        $this->assertStringContainsString('65% da altura', $berco['size']);
    }

    #[Test]
    public function caixa_sem_ferragem_nao_ganha_linha_nenhuma(): void
    {
        // A grande maioria das caixas não tem ímã. A lista de separação não pode
        // ganhar uma linha vazia de "ferragem: nenhuma" para provar isso.
        $quote = $this->orcar();

        $lista = $this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->assertOk()
            ->json('data.picking_list');

        $this->assertNotEmpty($lista, 'O papelão continua na lista.');
        $this->assertNull(collect($lista)->firstWhere('material_role', 'hardware'));
        $this->assertNull(collect($lista)->firstWhere('material_role', 'cradle'));
    }

    /* ── Isolamento entre empresas ─────────────────────────────────────── */

    #[Test]
    public function a_linha_nasce_com_a_empresa_do_orcamento(): void
    {
        /*
         * `tenant_id` denormalizado: a ficha técnica consulta os componentes
         * diretamente, e o TenantScope filtra a tabela CONSULTADA. Sem a coluna
         * essa consulta atravessaria empresas — o IDOR que a Fase 1 fechou.
         */
        $ima = $this->ima();

        $quote = $this->orcar(['components' => [
            ['material_id' => $ima->id, 'role' => 'hardware', 'quantity' => 2],
        ]]);

        $this->assertSame(
            $quote->tenant_id,
            $quote->components->first()->tenant_id,
        );
    }
}

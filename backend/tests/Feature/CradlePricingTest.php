<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CradleType;
use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Enums\UserRole;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Berços de acomodação.
 *
 * O berço é o item que o cartonageiro mais esquece de cobrar: não aparece na
 * foto da caixa fechada, mas pode dobrar o papelão e o tempo de montagem. Os
 * testes perseguem as formas de ele sumir da conta — e a de ser cobrado na
 * grandeza errada, que é pior, porque produz um número plausível.
 */
class CradlePricingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $usuario;

    private Material $papelao;

    private Material $espuma;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->create();
        $this->usuario = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::Admin,
        ]);

        CostSetting::factory()->create(['tenant_id' => $this->empresa->id]);

        $this->papelao = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'cost_per_unit' => 5.00,
            'thickness_mm' => 1.9,
        ]);

        $this->espuma = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'name' => 'Espuma EVA 40mm',
            'type' => MaterialType::Other,
            'cost_unit' => MaterialUnit::CubicMeter,
            'cost_per_unit' => 850.00,
            'grammage_kg_per_m2' => null,
            'thickness_mm' => 40.0,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function spec(array $overrides = []): array
    {
        return [
            'material_id' => $this->papelao->id,
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

    /** @param array<string, mixed> $extra */
    private function comBerco(string $tipo, Material $material, array $extra = []): TestResponse
    {
        return $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'cradle_type' => $tipo,
                'components' => [['material_id' => $material->id, 'role' => 'cradle']],
                ...$extra,
            ]))->assertOk();
    }

    /* ── A grandeza ────────────────────────────────────────────────────── */

    #[Test]
    public function a_espuma_e_cobrada_por_volume_e_nao_por_area(): void
    {
        $response = $this->comBerco('foam', $this->espuma);

        /*
         * O erro perigoso deste módulo: tratar espuma como chapa. O número
         * sairia plausível — e errado por uma ordem de grandeza. Volume > 0 e
         * área == 0 é a assinatura de que a grandeza certa foi usada.
         */
        $this->assertGreaterThan(0, $response->json('data.cradle_volume_m3_per_unit'));
        $this->assertSame(0, $response->json('data.cradle_area_m2_per_unit'));
        $this->assertGreaterThan(0, $response->json('data.cradle_cost'));
    }

    #[Test]
    public function os_bercos_de_cartonagem_sao_cobrados_por_area(): void
    {
        foreach (['board_niche', 'paper_niche', 'paper_fold'] as $tipo) {
            $response = $this->comBerco($tipo, $this->papelao);

            $this->assertGreaterThan(
                0,
                $response->json('data.cradle_area_m2_per_unit'),
                "berço {$tipo} deveria consumir área",
            );

            $this->assertSame(0, $response->json('data.cradle_volume_m3_per_unit'));
        }
    }

    #[Test]
    public function espuma_com_material_de_area_falha_de_forma_explicita(): void
    {
        /*
         * Pedir berço de espuma com um papelão cotado em m² não pode produzir
         * um preço: multiplicaria volume por R$/m². A guarda do Material lança
         * DomainException, que vira 422 com a mensagem visível.
         */
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'cradle_type' => 'foam',
                'components' => [['material_id' => $this->papelao->id, 'role' => 'cradle']],
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.pricing.0', fn ($m) => str_contains($m, 'metro cúbico'));
    }

    /* ── O tempo ───────────────────────────────────────────────────────── */

    #[Test]
    public function o_berco_acrescenta_tempo_de_montagem(): void
    {
        $semBerco = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())->assertOk();

        $comNichos = $this->comBerco('board_niche', $this->papelao);

        // Nichos revestidos custam 8 minutos que ninguém lembra de lançar.
        $this->assertSame(0, $semBerco->json('data.cradle_minutes'));
        $this->assertSame(8, $comNichos->json('data.cradle_minutes'));

        // E o tempo vira dinheiro: a mão de obra sobe junto.
        $this->assertGreaterThan(
            $semBerco->json('data.labor_cost'),
            $comNichos->json('data.labor_cost'),
        );
    }

    #[Test]
    public function a_espuma_custa_mais_material_e_menos_tempo_que_os_nichos(): void
    {
        $espuma = $this->comBerco('foam', $this->espuma);
        $nichos = $this->comBerco('board_niche', $this->papelao);

        /*
         * A troca que o modelo descreve: espuma sai pronta do corte a laser
         * (1,5 min) e os nichos são cortados, revestidos e colados à mão (8).
         */
        $this->assertLessThan(
            $nichos->json('data.cradle_minutes'),
            $espuma->json('data.cradle_minutes'),
        );

        $this->assertGreaterThan(
            $nichos->json('data.cradle_cost'),
            $espuma->json('data.cradle_cost'),
        );
    }

    /* ── A grade ───────────────────────────────────────────────────────── */

    #[Test]
    public function a_grade_maior_consome_mais_tiras(): void
    {
        $pequena = $this->comBerco('divider_grid', $this->papelao, [
            'cradle_rows' => 2, 'cradle_columns' => 2,
        ]);

        $grande = $this->comBerco('divider_grid', $this->papelao, [
            'cradle_rows' => 4, 'cradle_columns' => 5,
        ]);

        // 2×2 = 2 tiras; 4×5 = 3 + 4 = 7 tiras.
        $this->assertGreaterThan(
            $pequena->json('data.cradle_area_m2_per_unit'),
            $grande->json('data.cradle_area_m2_per_unit'),
        );
    }

    #[Test]
    public function grade_um_por_um_e_sem_divisoria(): void
    {
        $response = $this->comBerco('divider_grid', $this->papelao, [
            'cradle_rows' => 1, 'cradle_columns' => 1,
        ]);

        /*
         * Uma caixa dividida em uma parte só não tem divisória. Zero é o
         * resultado certo para quem selecionou a opção e não configurou nada —
         * e nada pode estourar por dividir a caixa em uma parte.
         */
        $this->assertSame(0, $response->json('data.cradle_area_m2_per_unit'));
        $this->assertSame(0, $response->json('data.cradle_cost'));
    }

    #[Test]
    public function grade_densa_em_caixa_estreita_nao_produz_area_negativa(): void
    {
        $grosso = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'cost_per_unit' => 5.00,
            'thickness_mm' => 5.0,
        ]);

        /*
         * 8×8 numa caixa de 60mm com tiras de 5mm: as sete tiras que cruzam
         * consomem 35mm dos 60. Se a conta não travar em zero, o comprimento
         * vira negativo e a área também — um berço de custo negativo baratearia
         * a caixa quanto mais dividida ela fosse.
         */
        $response = $this->comBerco('divider_grid', $grosso, [
            'width_mm' => 60, 'depth_mm' => 60,
            'cradle_rows' => 8, 'cradle_columns' => 8,
        ]);

        $this->assertGreaterThanOrEqual(0, $response->json('data.cradle_area_m2_per_unit'));
        $this->assertGreaterThanOrEqual(0, $response->json('data.cradle_cost'));
    }

    /* ── A altura ──────────────────────────────────────────────────────── */

    #[Test]
    public function o_berco_de_meia_altura_consome_menos(): void
    {
        $cheio = $this->comBerco('board_niche', $this->papelao);
        $meio = $this->comBerco('board_niche', $this->papelao, ['cradle_height_ratio' => 0.5]);

        $this->assertGreaterThan(
            $meio->json('data.cradle_area_m2_per_unit'),
            $cheio->json('data.cradle_area_m2_per_unit'),
        );
    }

    /* ── Integração com o resto ────────────────────────────────────────── */

    #[Test]
    public function sem_berco_os_campos_saem_zerados(): void
    {
        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())->assertOk();

        $this->assertSame(0, $response->json('data.cradle_cost'));
        $this->assertSame(0, $response->json('data.cradle_minutes'));
        $this->assertSame(0, $response->json('data.cradle_area_m2_per_unit'));
        $this->assertSame(0, $response->json('data.cradle_volume_m3_per_unit'));
    }

    #[Test]
    public function o_componente_de_berco_sem_tipo_e_ignorado(): void
    {
        /*
         * Material com papel `cradle` mas sem `cradle_type` não descreve uma
         * construção. Ignorar em vez de adivinhar: escolher um tipo pelo
         * sistema cobraria uma peça que o usuário não pediu.
         */
        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'components' => [['material_id' => $this->papelao->id, 'role' => 'cradle']],
            ]))->assertOk();

        $this->assertSame(0, $response->json('data.cradle_cost'));
    }

    #[Test]
    public function o_berco_vale_em_caixa_dobrada_e_em_rigida(): void
    {
        // Caixa dobrada também acomoda: o berço não é privilégio da rígida.
        $dobrada = $this->comBerco('paper_fold', $this->papelao, ['box_model' => 'rsc']);
        $rigida = $this->comBerco('paper_fold', $this->papelao, ['box_model' => 'rigid_telescopic']);

        $this->assertGreaterThan(0, $dobrada->json('data.cradle_cost'));
        $this->assertGreaterThan(0, $rigida->json('data.cradle_cost'));
    }

    #[Test]
    public function o_berco_de_outra_empresa_nao_e_utilizavel(): void
    {
        $vizinha = Tenant::factory()->create();
        $alheio = Material::factory()->create(['tenant_id' => $vizinha->id]);

        $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'cradle_type' => 'paper_fold',
                'components' => [['material_id' => $alheio->id, 'role' => 'cradle']],
            ]))
            ->assertNotFound();
    }

    /* ── O enum ────────────────────────────────────────────────────────── */

    #[Test]
    public function cada_tipo_declara_sua_grandeza_e_seu_tempo(): void
    {
        $this->assertTrue(CradleType::Foam->isVolumetric());
        $this->assertFalse(CradleType::BoardNiche->isVolumetric());

        $this->assertTrue(CradleType::DividerGrid->needsGrid());
        $this->assertFalse(CradleType::Foam->needsGrid());

        // Os cinco tipos precisam ter tempo declarado — um zero silencioso
        // faria o berço mais trabalhoso não custar mão de obra nenhuma.
        foreach (CradleType::cases() as $tipo) {
            $this->assertGreaterThan(0, $tipo->extraProductionMinutes(), $tipo->value);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Testes de contrato e de segurança da API de orçamentos.
 *
 * Foco deliberado nas garantias que o código AFIRMA oferecer — preço definido
 * apenas no servidor, isolamento entre usuários, barreira de admin. Uma
 * afirmação de segurança sem teste é só um comentário.
 */
class QuoteApiTest extends TestCase
{
    use RefreshDatabase;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        // Configuração vigente: sem ela nenhum orçamento pode ser calculado.
        CostSetting::factory()->create();

        $this->material = Material::factory()->create([
            'cost_per_unit' => 3.20,
            'default_waste_percent' => 10.0,
            'thickness_mm' => 0.0,
        ]);
    }

    /** @return array<string, mixed> */
    private function spec(array $overrides = []): array
    {
        return [
            'material_id' => $this->material->id,
            'box_model' => 'rsc',
            'width_mm' => 300,
            'height_mm' => 200,
            'depth_mm' => 150,
            'quantity' => 100,
            'waste_percent' => 10,
            'production_minutes_per_unit' => 2.5,
            'profit_margin_percent' => 30,
            'pricing_mode' => 'markup',
            ...$overrides,
        ];
    }

    /* ── Autenticação ──────────────────────────────────────────────────── */

    #[Test]
    public function a_api_exige_autenticacao(): void
    {
        $this->postJson('/api/quotes/simulate', $this->spec())->assertUnauthorized();
        $this->getJson('/api/quotes')->assertUnauthorized();
        $this->getJson('/api/materials')->assertUnauthorized();
    }

    /* ── Simulação ─────────────────────────────────────────────────────── */

    #[Test]
    public function simula_o_preco_sem_persistir(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/quotes/simulate', $this->spec());

        $response->assertOk()
            ->assertJsonPath('data.area_m2_per_unit', 0.32725)
            ->assertJsonPath('data.unit_cost', 5.3186)
            ->assertJsonPath('data.unit_price', 6.9142)
            // A cor do material acompanha a resposta para o Canvas 3D.
            ->assertJsonPath('data.material.color_hex', $this->material->color_hex);

        $this->assertDatabaseCount('quotes', 0);
    }

    #[Test]
    public function rejeita_dimensoes_fora_dos_limites_fisicos(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/quotes/simulate', $this->spec(['width_mm' => 5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('width_mm');

        $this->actingAs($user)
            ->postJson('/api/quotes/simulate', $this->spec(['height_mm' => 99999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('height_mm');
    }

    #[Test]
    public function rejeita_material_inativo(): void
    {
        $inactive = Material::factory()->inactive()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/quotes/simulate', $this->spec(['material_id' => $inactive->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('material_id');
    }

    /* ── Tampa informada pelo usuário ──────────────────────────────────── */

    #[Test]
    public function aceita_as_medidas_de_tampa_informadas(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/quotes/simulate', $this->spec([
                'box_model' => 'tray',
                'lid_width_mm' => 340,
                'lid_depth_mm' => 190,
                'lid_height_mm' => 120,
            ]))
            ->assertOk()
            ->assertJsonPath('data.lid_width_mm', 340)
            ->assertJsonPath('data.lid_height_mm', 120);
    }

    #[Test]
    public function rejeita_tampa_menor_que_a_caixa(): void
    {
        // A tampa encaixa por fora: menor que a base é peça impossível.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/quotes/simulate', $this->spec([
                'box_model' => 'tray',
                'lid_width_mm' => 250, // base tem 300
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lid_width_mm');

        $this->actingAs($user)
            ->postJson('/api/quotes/simulate', $this->spec([
                'box_model' => 'tray',
                'lid_depth_mm' => 100, // base tem 150
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lid_depth_mm');
    }

    #[Test]
    public function o_orcamento_salvo_preserva_o_modo_da_tampa(): void
    {
        $user = User::factory()->create();

        // Automático: os campos ficam nulos, e null aqui SIGNIFICA algo —
        // é a escolha de deixar o sistema derivar da base.
        $this->actingAs($user)
            ->postJson('/api/quotes', $this->spec(['box_model' => 'tray', 'client_name' => 'Auto']))
            ->assertCreated();

        $this->assertNull(Quote::latest('id')->first()->lid_height_mm);

        // Manual: a medida informada é gravada como tal.
        $this->actingAs($user)
            ->postJson('/api/quotes', $this->spec([
                'box_model' => 'tray',
                'client_name' => 'Manual',
                'lid_height_mm' => 120,
            ]))
            ->assertCreated();

        $this->assertSame(120.0, (float) Quote::latest('id')->first()->lid_height_mm);
    }

    /* ── Gravação: a garantia central ──────────────────────────────────── */

    #[Test]
    public function o_preco_enviado_pelo_cliente_e_ignorado(): void
    {
        // Cenário de ataque: o usuário adultera o payload no navegador para
        // gravar um orçamento de R$ 1,00. O servidor recalcula e descarta.
        $payload = $this->spec([
            'client_name' => 'Cliente Teste',
            'total_price' => 1.00,
            'unit_price' => 0.01,
            'unit_cost' => 0.00,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/quotes', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.pricing.unit_price', 6.9142)
            ->assertJsonPath('data.pricing.total_price', 691.42);

        $quote = Quote::first();
        $this->assertSame(691.42, (float) $quote->total_price);
        $this->assertNotSame(1.00, (float) $quote->total_price);
    }

    #[Test]
    public function exige_o_nome_do_cliente_para_salvar(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/quotes', $this->spec())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_name');
    }

    #[Test]
    public function grava_o_snapshot_dos_parametros_vigentes(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/quotes', $this->spec(['client_name' => 'ACME']))
            ->assertCreated();

        $snapshot = Quote::first()->pricing_snapshot;

        // 1.3.0 desde os berços. Fixar a versão aqui é intencional:
        // o snapshot é o que explica o preço de um orçamento antigo, e uma
        // mudança de motor que passe despercebida torna o histórico mudo.
        $this->assertSame('1.3.0', $snapshot['engine_version']);
        $this->assertSame(3.20, $snapshot['material']['cost_per_unit']);
        $this->assertSame(3.20, $snapshot['material_cost_per_m2']);
        $this->assertArrayHasKey('cost_settings', $snapshot);
        $this->assertArrayHasKey('breakdown', $snapshot);
    }

    #[Test]
    public function o_orcamento_emitido_nao_muda_quando_o_material_encarece(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/quotes', $this->spec(['client_name' => 'ACME']))
            ->assertCreated();

        $original = Quote::first()->total_price;

        // Reajuste de 100% no material, depois da emissão.
        $this->material->update(['cost_per_unit' => 6.40]);

        // O documento já emitido permanece intacto.
        $this->assertSame($original, Quote::first()->fresh()->total_price);

        // Mas um novo orçamento reflete o preço novo.
        $this->actingAs($user)
            ->postJson('/api/quotes/simulate', $this->spec())
            ->assertJsonPath('data.material_cost', 2.3038);
    }

    #[Test]
    public function gera_referencias_unicas_e_sequenciais(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $i) {
            $this->actingAs($user)
                ->postJson('/api/quotes', $this->spec(['client_name' => "Cliente {$i}"]))
                ->assertCreated();
        }

        $references = Quote::orderBy('id')->pluck('reference')->all();
        $year = now()->year;

        $this->assertSame([
            "ORC-{$year}-000001",
            "ORC-{$year}-000002",
            "ORC-{$year}-000003",
        ], $references);
    }

    /* ── Isolamento entre usuários (IDOR) ──────────────────────────────── */

    #[Test]
    public function um_usuario_nao_acessa_o_orcamento_de_outro(): void
    {
        $dono = User::factory()->create();
        $intruso = User::factory()->create();

        $this->actingAs($dono)
            ->postJson('/api/quotes', $this->spec(['client_name' => 'Confidencial']))
            ->assertCreated();

        $quote = Quote::first();

        // Trocar o id na URL não dá acesso.
        $this->actingAs($intruso)->getJson("/api/quotes/{$quote->id}")->assertForbidden();
        $this->actingAs($intruso)->patchJson("/api/quotes/{$quote->id}", ['status' => 'approved'])->assertForbidden();
        $this->actingAs($intruso)->deleteJson("/api/quotes/{$quote->id}")->assertForbidden();

        // Nem aparece na listagem do intruso.
        $this->actingAs($intruso)->getJson('/api/quotes')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function o_admin_enxerga_os_orcamentos_de_todos(): void
    {
        $usuario = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($usuario)
            ->postJson('/api/quotes', $this->spec(['client_name' => 'ACME']))
            ->assertCreated();

        $this->actingAs($admin)->getJson('/api/quotes')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/quotes/'.Quote::first()->id)->assertOk();
    }

    #[Test]
    public function a_exclusao_e_logica_e_preserva_o_historico(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/quotes', $this->spec(['client_name' => 'ACME']))
            ->assertCreated();

        $quote = Quote::first();

        $this->actingAs($user)->deleteJson("/api/quotes/{$quote->id}")->assertNoContent();

        $this->assertSoftDeleted('quotes', ['id' => $quote->id]);
        $this->assertDatabaseCount('quotes', 1);
    }
}

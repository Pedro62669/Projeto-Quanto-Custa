<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Enums\UserRole;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Barreira administrativa e cadastros restritos.
 */
class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /* ── Barreira ──────────────────────────────────────────────────────── */

    #[Test]
    public function usuario_comum_nao_acessa_nenhuma_rota_de_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/admin/materials')->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/materials', [])->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/cost-settings')->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/cost-settings', [])->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/users')->assertForbidden();
    }

    #[Test]
    public function admin_desativado_perde_o_acesso(): void
    {
        // is_active = false deve barrar mesmo com role = admin: desativar uma
        // conta precisa revogar o poder administrativo imediatamente.
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => false]);

        $this->actingAs($admin)->getJson('/api/admin/materials')->assertForbidden();
    }

    /* ── Matérias-primas ───────────────────────────────────────────────── */

    #[Test]
    public function admin_cadastra_materia_prima(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/materials', [
                'name' => 'Papelão microondulado',
                'type' => 'cardboard',
                'cost_unit' => 'm2',
                'cost_per_unit' => 2.75,
                'default_waste_percent' => 8,
                'thickness_mm' => 1.2,
                'color_hex' => '#C8A06A',
            ])
            ->assertCreated()
            ->assertJsonPath('data.cost_per_m2', 2.75);

        $this->assertDatabaseHas('materials', ['name' => 'Papelão microondulado']);
    }

    #[Test]
    public function material_cotado_em_quilo_exige_gramatura(): void
    {
        // Sem gramatura não há como converter R$/kg em R$/m², e o cálculo
        // quebraria em runtime. A validação precisa barrar na entrada.
        $this->actingAs($this->admin())
            ->postJson('/api/admin/materials', [
                'name' => 'Tecido sem gramatura',
                'type' => 'fabric',
                'cost_unit' => 'kg',
                'cost_per_unit' => 24.00,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('grammage_kg_per_m2');
    }

    #[Test]
    public function rejeita_cor_em_formato_invalido(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/materials', [
                'name' => 'X',
                'type' => 'paper',
                'cost_unit' => 'm2',
                'cost_per_unit' => 1.00,
                'color_hex' => 'vermelho',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('color_hex');
    }

    #[Test]
    public function excluir_material_apenas_o_desativa(): void
    {
        $material = Material::factory()->create();

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/materials/{$material->id}")
            ->assertOk();

        // A linha permanece: orçamentos antigos apontam para ela (FK RESTRICT).
        $this->assertDatabaseHas('materials', ['id' => $material->id, 'is_active' => false]);
    }

    #[Test]
    public function o_usuario_comum_nao_ve_o_preco_de_compra(): void
    {
        Material::factory()->byWeight()->create();

        // Usuário comum: só o custo normalizado, necessário para simular.
        $response = $this->actingAs(User::factory()->create())->getJson('/api/materials');

        $response->assertOk()->assertJsonPath('data.0.cost_per_m2', 2.55);
        $this->assertArrayNotHasKey('cost_per_unit', $response->json('data.0'));
        $this->assertArrayNotHasKey('grammage_kg_per_m2', $response->json('data.0'));

        // Admin: vê a negociação com o fornecedor.
        $adminResponse = $this->actingAs($this->admin())->getJson('/api/admin/materials');
        $this->assertArrayHasKey('cost_per_unit', $adminResponse->json('data.0'));
    }

    #[Test]
    public function o_cadastro_de_material_devolve_tudo_que_aceita(): void
    {
        /*
         * Assimetria entre escrita e leitura destrói dado.
         *
         * O formulário de edição preenche os campos com o que a API devolve. Um
         * campo aceito no PUT e ausente no GET chega vazio à tela, e o próximo
         * "salvar" — mesmo sem ninguém tocar nele — grava null por cima do que
         * existia. Foi o que acontecia com o lote e a medida da folha: o custo
         * por m² mudava junto, porque o lote tem precedência no cálculo.
         *
         * Este teste percorre o ciclo inteiro: grava, relê, e exige que cada
         * campo enviado volte igual.
         */
        $enviado = [
            'name' => 'Papelão ondulado B',
            'type' => MaterialType::Cardboard->value,
            'cost_unit' => MaterialUnit::SquareMeter->value,
            'cost_per_unit' => 4.20,
            'default_waste_percent' => 12.0,
            'thickness_mm' => 3.0,
            'sheet_width_mm' => 1000,
            'sheet_length_mm' => 800,
            'grain_direction' => 'length',
            'lot_quantity' => 250,
            'lot_purchase_cost' => 890.0,
            'lot_freight_cost' => 60.0,
            'color_hex' => '#B0B0B0',
        ];

        $criado = $this->actingAs($this->admin())
            ->postJson('/api/admin/materials', $enviado)
            ->assertCreated();

        $id = $criado->json('data.id');

        $lido = $this->actingAs($this->admin())
            ->getJson("/api/admin/materials/{$id}")
            ->assertOk();

        foreach ($enviado as $campo => $valor) {
            $this->assertEquals(
                $valor,
                $lido->json("data.{$campo}"),
                "O campo '{$campo}' é aceito na escrita e não volta igual na leitura."
            );
        }

        // O custo do lote com frete tem precedência: (890 + 60) / 250 folhas,
        // divididos pela área de 0,8 m² da folha.
        $this->assertSame(4.75, $lido->json('data.cost_per_m2'));
    }

    #[Test]
    public function a_lista_de_materiais_sobrevive_a_ferragem_e_a_espuma(): void
    {
        /*
         * Um ímã e um bloco de espuma não têm custo por m² — `costPerSquareMeter()`
         * RECUSA convertê-los, e com razão. O recurso chamava o método em todos os
         * materiais: bastava a empresa cadastrar uma ferragem para GET /materials
         * responder 500 e a calculadora inteira parar de abrir.
         */
        Material::factory()->create(['name' => 'Papelão cinza 1,9mm']);
        Material::factory()->hardware()->create(['name' => 'Ima de neodimio']);
        Material::factory()->create([
            'name' => 'Espuma EVA',
            'type' => MaterialType::Other,
            'cost_unit' => MaterialUnit::CubicMeter,
            'cost_per_unit' => 320.0,
        ]);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/materials');

        $response->assertOk()->assertJsonCount(3, 'data');

        $porNome = collect($response->json('data'))->keyBy('name');

        // O que tem área continua entregando o número que o motor consome.
        $this->assertSame(3.2, $porNome['Papelão cinza 1,9mm']['cost_per_m2']);
        $this->assertTrue($porNome['Papelão cinza 1,9mm']['is_area_based']);

        /*
         * Null, e não zero: zero é um custo, e um custo de zero precificaria a
         * caixa como se o material fosse de graça. Null diz "esta grandeza não
         * existe aqui" — e é o que a tela usa para não oferecer ferragem onde se
         * pede uma peça medida em milímetros.
         */
        $this->assertNull($porNome['Ima de neodimio']['cost_per_m2']);
        $this->assertFalse($porNome['Ima de neodimio']['is_area_based']);
        $this->assertNull($porNome['Espuma EVA']['cost_per_m2']);
    }

    /* ── Custos fixos ──────────────────────────────────────────────────── */

    #[Test]
    public function publicar_custos_cria_nova_versao_sem_apagar_a_anterior(): void
    {
        CostSetting::factory()->create(['energy_tariff_per_kwh' => 0.92]);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/cost-settings', [
                'energy_tariff_per_kwh' => 1.05,
                'machine_hour_rate' => 45.00,
                'machine_power_kw' => 7.50,
                'labor_hour_rate' => 30.00,
            ])
            ->assertCreated();

        // Histórico preservado: duas versões coexistem.
        $this->assertDatabaseCount('cost_settings', 2);

        // A vigente é a mais recente.
        $this->assertSame(1.05, CostSetting::current()->energy_tariff_per_kwh);
    }

    #[Test]
    public function a_configuracao_vigente_ignora_versoes_futuras(): void
    {
        CostSetting::factory()->create([
            'energy_tariff_per_kwh' => 0.92,
            'effective_from' => now()->subDay(),
        ]);

        // Reajuste agendado para o mês que vem: ainda não vale.
        CostSetting::factory()->create([
            'energy_tariff_per_kwh' => 2.00,
            'effective_from' => now()->addMonth(),
        ]);

        $this->assertSame(0.92, CostSetting::current()->energy_tariff_per_kwh);
    }

    /* ── Usuários ──────────────────────────────────────────────────────── */

    #[Test]
    public function admin_cria_usuario_com_senha_hasheada(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/users', [
                'name' => 'Novo Operador',
                'email' => 'operador@teste.local',
                'password' => 'senha-secreta-123',
                'role' => 'user',
            ])
            ->assertCreated();

        $created = User::where('email', 'operador@teste.local')->first();

        $this->assertNotSame('senha-secreta-123', $created->password);
        $this->assertTrue(password_verify('senha-secreta-123', $created->password));
    }

    #[Test]
    public function o_ultimo_admin_ativo_nao_pode_ser_rebaixado(): void
    {
        // Trava contra o sistema ficar sem nenhum administrador.
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$admin->id}", ['role' => 'user'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    #[Test]
    public function um_admin_pode_ser_rebaixado_se_houver_outro(): void
    {
        $primeiro = $this->admin();
        $segundo = $this->admin();

        $this->actingAs($primeiro)
            ->patchJson("/api/admin/users/{$segundo->id}", ['role' => 'user'])
            ->assertOk();

        $this->assertSame(UserRole::User, $segundo->fresh()->role);
    }

    #[Test]
    public function ninguem_desativa_a_propria_conta(): void
    {
        $admin = $this->admin();
        $this->admin(); // segundo admin, para isolar a trava do "último admin"

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertUnprocessable();
    }
}

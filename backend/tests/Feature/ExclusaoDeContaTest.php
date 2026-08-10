<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccessLog;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Direito ao esquecimento — LGPD (Lei 13.709/2018, art. 18, VI).
 *
 * O teste central não é "a rota respondeu 200": é que NADA sobrou. Cada
 * assertivo aqui persegue um lugar onde dado pessoal costuma ficar para trás
 * — tabela filha, token órfão, arquivo no disco, soft delete.
 */
class ExclusaoDeContaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->create(['name' => 'Cartonagem do Zé']);

        $this->dono = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::Admin,
            'password' => 'senha-do-dono',
        ]);
    }

    /** @return array<string, string> */
    private function confirmacao(string $senha = 'senha-do-dono'): array
    {
        return ['password' => $senha, 'confirmacao' => 'EXCLUIR'];
    }

    private function povoar(): Quote
    {
        $material = Material::factory()->create(['tenant_id' => $this->empresa->id]);
        CostSetting::factory()->create(['tenant_id' => $this->empresa->id]);

        return Quote::create([
            'tenant_id' => $this->empresa->id,
            'user_id' => $this->dono->id,
            'material_id' => $material->id,
            'box_model' => 'rsc',
            'width_mm' => 300, 'height_mm' => 200, 'depth_mm' => 150,
            'quantity' => 100, 'waste_percent' => 10,
            'production_minutes_per_unit' => 2.5, 'profit_margin_percent' => 30,
            'client_name' => 'Cliente', 'area_m2_per_unit' => 0.3,
            'area_m2_total' => 30.0, 'material_cost' => 100.0,
            'labor_cost' => 10.0, 'machine_cost' => 10.0, 'energy_cost' => 1.0,
            'overhead_cost' => 0.0, 'unit_cost' => 5.0, 'unit_price' => 6.5,
            'total_cost' => 500.0, 'total_price' => 650.0, 'profit_amount' => 150.0,
            'pricing_snapshot' => [],
        ]);
    }

    /* ── Autorização ───────────────────────────────────────────────────── */

    #[Test]
    public function a_rota_exige_autenticacao(): void
    {
        $this->deleteJson('/api/account', $this->confirmacao())->assertUnauthorized();
    }

    #[Test]
    public function usuario_comum_nao_apaga_a_empresa(): void
    {
        $funcionario = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::User,
            'password' => 'senha-do-dono',
        ]);

        $this->actingAs($funcionario)
            ->deleteJson('/api/account', $this->confirmacao())
            ->assertForbidden();

        $this->assertDatabaseHas('tenants', ['id' => $this->empresa->id]);
    }

    #[Test]
    public function a_senha_errada_barra_a_exclusao(): void
    {
        $this->actingAs($this->dono)
            ->deleteJson('/api/account', $this->confirmacao('chute'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('tenants', ['id' => $this->empresa->id]);
    }

    #[Test]
    public function a_confirmacao_escrita_e_obrigatoria(): void
    {
        $this->actingAs($this->dono)
            ->deleteJson('/api/account', ['password' => 'senha-do-dono', 'confirmacao' => 'sim'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmacao');

        $this->assertDatabaseHas('tenants', ['id' => $this->empresa->id]);
    }

    /* ── A exclusão em si ──────────────────────────────────────────────── */

    #[Test]
    public function apaga_a_empresa_e_tudo_que_pende_dela(): void
    {
        $orcamento = $this->povoar();

        $this->actingAs($this->dono)
            ->deleteJson('/api/account', $this->confirmacao())
            ->assertOk()
            ->assertJsonPath('data.inventario.empresa', 'Cartonagem do Zé')
            ->assertJsonPath('data.inventario.usuarios', 1)
            ->assertJsonPath('data.inventario.orcamentos', 1);

        $this->assertDatabaseMissing('tenants', ['id' => $this->empresa->id]);
        $this->assertDatabaseMissing('users', ['id' => $this->dono->id]);
        $this->assertDatabaseMissing('materials', ['tenant_id' => $this->empresa->id]);
        $this->assertDatabaseMissing('cost_settings', ['tenant_id' => $this->empresa->id]);

        /*
         * withTrashed: Quote usa SoftDeletes, e "apagado" para a LGPD não pode
         * significar uma linha marcada como apagada que continua no banco.
         */
        $this->assertSame(0, Quote::query()
            ->withoutGlobalScope(TenantScope::class)
            ->withTrashed()
            ->whereKey($orcamento->id)
            ->count());
    }

    #[Test]
    public function os_tokens_de_acesso_morrem_junto(): void
    {
        $this->dono->createToken('celular');
        $this->dono->createToken('notebook');

        $this->assertSame(2, DB::table('personal_access_tokens')->count());

        $this->actingAs($this->dono)
            ->deleteJson('/api/account', $this->confirmacao())
            ->assertOk();

        /*
         * A tabela do Sanctum é polimórfica e não tem chave estrangeira: nenhuma
         * cascata a alcança. Sem a limpeza explícita, sobrariam credenciais
         * válidas de uma conta que o titular mandou apagar.
         */
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    #[Test]
    public function o_logotipo_e_apagado_do_disco(): void
    {
        Storage::fake(config('filesystems.default'));

        $caminho = 'logos/marca.png';
        Storage::disk(config('filesystems.default'))->put($caminho, 'conteudo-binario');

        $this->empresa->update(['logo_path' => $caminho]);

        $this->actingAs($this->dono)
            ->deleteJson('/api/account', $this->confirmacao())
            ->assertOk()
            ->assertJsonPath('data.inventario.logotipo_removido', true);

        Storage::disk(config('filesystems.default'))->assertMissing($caminho);
    }

    #[Test]
    public function a_exclusao_nao_alcanca_outras_empresas(): void
    {
        $vizinha = Tenant::factory()->create();
        $materialVizinho = Material::factory()->create(['tenant_id' => $vizinha->id]);

        $this->povoar();

        $this->actingAs($this->dono)
            ->deleteJson('/api/account', $this->confirmacao())
            ->assertOk();

        $this->assertDatabaseHas('tenants', ['id' => $vizinha->id]);
        $this->assertDatabaseHas('materials', ['id' => $materialVizinho->id]);
    }

    /* ── A tensão entre as duas leis ───────────────────────────────────── */

    #[Test]
    public function os_registros_de_acesso_sobrevivem_anonimizados(): void
    {
        // Gera um registro de acesso vinculado ao titular.
        $this->postJson('/api/login', [
            'email' => $this->dono->email,
            'password' => 'senha-do-dono',
        ])->assertOk();

        $antes = AccessLog::query()->where('user_id', $this->dono->id)->sole();
        $hashDoSujeito = $antes->subject_hash;

        $this->assertNotNull($hashDoSujeito);

        $this->actingAs($this->dono)
            ->deleteJson('/api/account', $this->confirmacao())
            ->assertOk();

        $depois = AccessLog::query()->whereKey($antes->id)->sole();

        /*
         * O encontro das duas leis. A LGPD manda eliminar o dado pessoal; o
         * Marco Civil manda guardar o registro de acesso por seis meses, e a
         * própria LGPD (art. 16, I) reconhece a obrigação legal como exceção.
         *
         * A saída é o registro sobreviver SEM o dado pessoal: os vínculos caem
         * para null, o pseudônimo assinado permanece, e a prova de que houve
         * acesso continua de pé sem apontar para uma pessoa identificável.
         */
        $this->assertNull($depois->user_id, 'o vínculo pessoal tem que sumir');
        $this->assertNull($depois->tenant_id);
        $this->assertSame($hashDoSujeito, $depois->subject_hash, 'o pseudônimo permanece');
        $this->assertNotEmpty($depois->ip_address);

        // E a assinatura continua conferindo: anonimizar não é adulterar.
        $this->assertTrue(
            $depois->integro(),
            'a anonimização não pode invalidar a prova de autenticidade',
        );
    }

    #[Test]
    public function a_propria_exclusao_fica_auditada(): void
    {
        $this->actingAs($this->dono)
            ->deleteJson('/api/account', $this->confirmacao())
            ->assertOk();

        // O ato de apagar é o evento mais sensível do sistema: precisa deixar
        // rastro, ainda que já não haja a quem vinculá-lo.
        $log = AccessLog::query()->where('event', 'conta.exclusao')->sole();

        $this->assertNull($log->user_id);
        $this->assertNotNull($log->subject_hash);
        $this->assertSame(200, $log->status_code);
    }
}

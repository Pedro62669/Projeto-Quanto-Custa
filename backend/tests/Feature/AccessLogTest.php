<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Auditoria de acesso — Marco Civil da Internet (Lei 12.965/2014).
 *
 * A obrigação é guardar registro capaz de comprovar autenticidade. Cada teste
 * aqui cobre uma forma de o registro falhar nisso: não existir, existir com
 * dado a menos, existir com dado a mais (senha), ou poder ser alterado depois.
 */
class AccessLogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->usuario = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'dono@empresa.test',
            'password' => 'senha-correta',
        ]);
    }

    /* ── O que vira registro ───────────────────────────────────────────── */

    #[Test]
    public function o_login_bem_sucedido_vira_registro_com_ip_e_navegador(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Teste)'])
            ->postJson('/api/login', [
                'email' => 'dono@empresa.test',
                'password' => 'senha-correta',
            ])
            ->assertOk();

        $log = AccessLog::query()->where('event', 'login')->sole();

        $this->assertSame($this->usuario->id, $log->user_id);
        $this->assertSame($this->tenant->id, $log->tenant_id);
        $this->assertSame('Mozilla/5.0 (Teste)', $log->user_agent);
        $this->assertSame('POST', $log->method);
        $this->assertSame('api/login', $log->path);
        $this->assertSame(200, $log->status_code);
        $this->assertNotEmpty($log->ip_address);
        $this->assertNotNull($log->occurred_at);
    }

    #[Test]
    public function a_tentativa_frustrada_de_login_tambem_fica_registrada(): void
    {
        $this->postJson('/api/login', [
            'email' => 'dono@empresa.test',
            'password' => 'senha-errada',
        ])->assertUnprocessable();

        /*
         * O evento que uma investigação procura. Sem ele, uma sequência de
         * tentativas de invasão seria invisível no log — só o acesso que deu
         * certo apareceria, que é justamente o que o atacante quer.
         */
        $this->assertDatabaseHas('access_logs', [
            'event' => 'login.falha',
            'status_code' => 422,
        ]);
    }

    #[Test]
    public function a_senha_nunca_entra_no_registro(): void
    {
        $this->postJson('/api/login', [
            'email' => 'dono@empresa.test',
            'password' => 'senha-correta',
        ])->assertOk();

        $log = AccessLog::query()->sole();

        /*
         * Um log de auditoria que guarda o corpo da requisição guarda a senha
         * em claro. Trocaria um problema de conformidade por um vazamento —
         * e um vazamento com carimbo de data e IP.
         */
        $this->assertStringNotContainsString('senha-correta', json_encode($log->getAttributes()));
    }

    #[Test]
    public function a_escrita_de_dados_vira_registro(): void
    {
        $this->actingAs($this->usuario)
            ->postJson('/api/admin/materials', [
                'name' => 'Papelão auditado',
                'type' => 'cardboard',
                'cost_unit' => 'm2',
                'cost_per_unit' => 3.20,
            ]);

        $this->assertDatabaseHas('access_logs', [
            'user_id' => $this->usuario->id,
            'method' => 'POST',
            'path' => 'api/admin/materials',
        ]);
    }

    /* ── O que NÃO vira registro ───────────────────────────────────────── */

    #[Test]
    public function a_leitura_nao_polui_a_auditoria(): void
    {
        $this->actingAs($this->usuario)->getJson('/api/materials')->assertOk();

        $this->assertSame(0, AccessLog::query()->count());
    }

    #[Test]
    public function a_simulacao_de_preco_nao_vira_registro(): void
    {
        Material::factory()->create(['tenant_id' => $this->tenant->id]);

        /*
         * É POST, mas não persiste nada e dispara em debounce a cada tecla
         * digitada na calculadora. Auditá-la encheria a tabela de ruído e
         * enterraria os eventos que importam.
         */
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', ['width_mm' => 10]);

        $this->assertSame(0, AccessLog::query()->count());
    }

    /* ── Imutabilidade ─────────────────────────────────────────────────── */

    #[Test]
    public function o_registro_nao_pode_ser_alterado(): void
    {
        $this->postJson('/api/login', [
            'email' => 'dono@empresa.test',
            'password' => 'senha-correta',
        ]);

        $log = AccessLog::query()->sole();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/imutável/');

        $log->update(['ip_address' => '10.0.0.1']);
    }

    #[Test]
    public function o_registro_nao_pode_ser_excluido_isoladamente(): void
    {
        $this->postJson('/api/login', [
            'email' => 'dono@empresa.test',
            'password' => 'senha-correta',
        ]);

        $this->expectException(\LogicException::class);

        AccessLog::query()->sole()->delete();
    }

    #[Test]
    public function a_assinatura_denuncia_adulteracao_feita_por_fora(): void
    {
        $this->postJson('/api/login', [
            'email' => 'dono@empresa.test',
            'password' => 'senha-correta',
        ]);

        $log = AccessLog::query()->sole();
        $this->assertTrue($log->integro(), 'o registro recém-gravado tem que conferir');

        /*
         * Simula quem tem acesso ao banco e edita o IP direto no SQL, passando
         * por fora do Eloquent — o caminho que a guarda de `updating` não
         * alcança. Sem a APP_KEY não há como recalcular a assinatura, e a
         * divergência torna a adulteração demonstrável.
         */
        DB::table('access_logs')
            ->where('id', $log->id)
            ->update(['ip_address' => '203.0.113.99']);

        $this->assertFalse(AccessLog::query()->sole()->integro());
    }

    /* ── Retenção ──────────────────────────────────────────────────────── */

    #[Test]
    public function o_expurgo_recusa_prazo_abaixo_do_minimo_legal(): void
    {
        $this->artisan('compliance:expurgar-acessos', ['--meses' => 3])
            ->expectsOutputToContain('Marco Civil')
            ->assertExitCode(1);
    }

    #[Test]
    public function o_expurgo_remove_apenas_o_que_venceu(): void
    {
        $this->postJson('/api/login', [
            'email' => 'dono@empresa.test',
            'password' => 'senha-correta',
        ]);

        // Envelhece o registro por fora do Eloquent: o model barra update.
        DB::table('access_logs')
            ->update(['occurred_at' => now()->subMonths(8)]);

        $recente = $this->postJson('/api/login', [
            'email' => 'dono@empresa.test',
            'password' => 'errada',
        ]);
        $recente->assertUnprocessable();

        $this->artisan('compliance:expurgar-acessos')->assertExitCode(0);

        $this->assertSame(1, AccessLog::query()->count());
        $this->assertSame('login.falha', AccessLog::query()->sole()->event);
    }
}

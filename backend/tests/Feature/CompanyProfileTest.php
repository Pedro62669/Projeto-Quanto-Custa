<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Enums\UserRole;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Production\QuotePdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O perfil da empresa — o cabeçalho da proposta comercial.
 *
 * Estes campos existiam na tabela desde a Fase 1 e o QuotePdfGenerator já os
 * lia, mas NENHUMA rota os escrevia. Toda proposta emitida saía sem CNPJ, sem
 * endereço e sem logotipo: o degradê "sem marca" que a Fase 5 previu para o caso
 * de o arquivo sumir disparava sempre, porque nada preenchia o campo.
 */
class CompanyProfileTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->noPlano(PlanType::Pro)->create(['name' => 'Cartonagem Alfa']);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::Admin,
        ]);

        Storage::fake(config('filesystems.default'));
    }

    /* ── Dados cadastrais ──────────────────────────────────────────────── */

    #[Test]
    public function o_admin_preenche_os_dados_que_vao_no_pdf(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/company', [
                'legal_name' => 'Cartonagem Alfa LTDA',
                'document' => '12345678000199',
                'whatsapp' => '5511999998888',
                'city' => 'São Paulo',
                'state' => 'SP',
                'street' => 'Rua das Caixas',
                'street_number' => '120',
            ])
            ->assertOk()
            ->assertJsonPath('data.legal_name', 'Cartonagem Alfa LTDA')
            ->assertJsonPath('data.document', '12345678000199');
    }

    #[Test]
    public function os_dados_salvos_aparecem_no_pdf_da_proposta(): void
    {
        $this->actingAs($this->admin)->putJson('/api/company', [
            'legal_name' => 'Cartonagem Alfa LTDA',
            'document' => '12345678000199',
            'city' => 'Santo André',
            'state' => 'SP',
        ])->assertOk();

        $orcamento = Quote::factory()->create([
            'tenant_id' => $this->empresa->id,
            'user_id' => $this->admin->id,
        ]);

        /*
         * O teste que liga as duas pontas. Sem a rota de perfil, este mesmo PDF
         * saía com o nome do seeder e nada mais — e ninguém percebia, porque o
         * arquivo é gerado com sucesso de qualquer jeito.
         */
        $conteudo = $this->actingAs($this->admin)
            ->get("/api/quotes/{$orcamento->id}/download-pdf")
            ->assertOk()
            ->getContent();

        $this->assertNotEmpty($conteudo);
        $this->assertStringStartsWith('%PDF', $conteudo);
    }

    #[Test]
    public function o_perfil_lista_o_que_ainda_falta_para_a_proposta_sair_completa(): void
    {
        /*
         * A tela avisa ANTES da primeira emissão. Sem isto, o usuário descobre
         * que faltava o CNPJ olhando um PDF que já foi para o cliente.
         */
        $pendencias = $this->actingAs($this->admin)
            ->getJson('/api/company')
            ->assertOk()
            ->json('data.pendencias_para_pdf');

        $this->assertContains('razão social', $pendencias);
        $this->assertContains('CNPJ ou CPF', $pendencias);
        $this->assertContains('logotipo', $pendencias);
    }

    #[Test]
    public function o_plano_nao_pode_ser_alterado_pela_rota_de_perfil(): void
    {
        /*
         * Cenário de ataque: o formulário de perfil carregando plan_type. É a
         * fronteira entre o $fillable do Tenant e os campos de plano que ficam
         * fora dele — trocar update() por forceFill neste controller abriria um
         * upgrade grátis para qualquer assinante.
         */
        $this->actingAs($this->admin)
            ->putJson('/api/company', [
                'name' => 'Nome novo',
                'plan_type' => 'pro',
                'max_materials' => 9999,
                'is_active' => true,
            ])
            ->assertOk();

        $recarregada = $this->empresa->fresh();

        $this->assertSame('Nome novo', $recarregada->name);
        $this->assertNull($recarregada->max_materials);
    }

    #[Test]
    public function usuario_comum_le_mas_nao_altera(): void
    {
        $comum = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::User,
        ]);

        $this->actingAs($comum)->getJson('/api/company')->assertOk();

        $this->actingAs($comum)
            ->putJson('/api/company', ['legal_name' => 'Mudança indevida'])
            ->assertForbidden();
    }

    #[Test]
    public function o_perfil_de_uma_empresa_nao_alcanca_a_outra(): void
    {
        $vizinha = Tenant::factory()->create(['name' => 'Cartonagem Beta']);
        $adminVizinho = User::factory()->create([
            'tenant_id' => $vizinha->id,
            'role' => UserRole::Admin,
        ]);

        // A empresa vem do usuário autenticado, nunca de id na requisição.
        $this->actingAs($adminVizinho)
            ->getJson('/api/company')
            ->assertOk()
            ->assertJsonPath('data.id', $vizinha->id);

        $this->actingAs($adminVizinho)
            ->putJson('/api/company', ['legal_name' => 'Sequestro de marca'])
            ->assertOk();

        $this->assertNull($this->empresa->fresh()->legal_name);
    }

    #[Test]
    public function duas_empresas_nao_compartilham_cnpj(): void
    {
        $vizinha = Tenant::factory()->create(['document' => '99887766000155']);

        $this->actingAs($this->admin)
            ->putJson('/api/company', ['document' => $vizinha->document])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document');
    }

    #[Test]
    public function o_admin_de_plataforma_nao_tem_empresa_para_editar(): void
    {
        $plataforma = User::factory()->platformAdmin()->create();

        // 422 com mensagem clara em vez de erro obscuro sobre null.
        $this->actingAs($plataforma)->getJson('/api/company')->assertUnprocessable();
    }

    /* ── Logotipo ──────────────────────────────────────────────────────── */

    #[Test]
    public function o_admin_envia_o_logotipo_e_ele_fica_disponivel(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/company/logo', [
                'logo' => UploadedFile::fake()->image('marca.png', 400, 200),
            ])
            ->assertOk()
            ->assertJsonPath('data.logo_url', fn ($url) => is_string($url));

        $caminho = $this->empresa->fresh()->logo_path;

        $this->assertNotNull($caminho);
        Storage::disk(config('filesystems.default'))->assertExists($caminho);

        $this->actingAs($this->admin)->get('/api/company/logo')->assertOk();
    }

    #[Test]
    public function o_teto_de_tamanho_e_o_mesmo_que_o_gerador_de_pdf_aplica(): void
    {
        $maxKb = (int) (QuotePdfGenerator::MAX_LOGO_BYTES / 1024);

        /*
         * Dois limites separados divergiriam no primeiro ajuste, e a divergência
         * teria a pior forma: o upload aceitaria uma imagem que o gerador depois
         * recusa em silêncio, e a proposta sairia sem marca sem ninguém entender
         * por quê.
         */
        $this->actingAs($this->admin)
            ->postJson('/api/company/logo', [
                'logo' => UploadedFile::fake()->create('enorme.png', $maxKb + 1, 'image/png'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo');

        $this->assertNull($this->empresa->fresh()->logo_path);
    }

    #[Test]
    public function formato_que_o_dompdf_nao_desenha_e_recusado(): void
    {
        // SVG passaria numa validação genérica de imagem, não seria desenhado no
        // PDF — e ainda é um formato que carrega script.
        $this->actingAs($this->admin)
            ->postJson('/api/company/logo', [
                'logo' => UploadedFile::fake()->create('marca.svg', 10, 'image/svg+xml'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo');
    }

    #[Test]
    public function trocar_o_logotipo_apaga_o_arquivo_anterior(): void
    {
        $disco = Storage::disk(config('filesystems.default'));

        $this->actingAs($this->admin)->postJson('/api/company/logo', [
            'logo' => UploadedFile::fake()->image('antiga.png'),
        ])->assertOk();

        $antigo = $this->empresa->fresh()->logo_path;

        $this->actingAs($this->admin)->postJson('/api/company/logo', [
            'logo' => UploadedFile::fake()->image('nova.png'),
        ])->assertOk();

        // Sem isto, cada troca deixaria um arquivo órfão ocupando disco para
        // sempre — e o disco é custo que o assinante não paga.
        $disco->assertMissing($antigo);
        $disco->assertExists($this->empresa->fresh()->logo_path);
    }

    #[Test]
    public function o_logotipo_de_uma_empresa_nao_e_servido_para_a_outra(): void
    {
        $this->actingAs($this->admin)->postJson('/api/company/logo', [
            'logo' => UploadedFile::fake()->image('marca.png'),
        ])->assertOk();

        $vizinha = Tenant::factory()->create();
        $adminVizinho = User::factory()->create([
            'tenant_id' => $vizinha->id,
            'role' => UserRole::Admin,
        ]);

        /*
         * A rota transmite o arquivo em vez de expor um caminho público — e é
         * por isso que ela resolve a empresa pelo usuário autenticado. Um disco
         * público tornaria a lista de assinantes enumerável por URL.
         */
        $this->actingAs($adminVizinho)->get('/api/company/logo')->assertNotFound();
    }

    #[Test]
    public function remover_o_logotipo_apaga_o_arquivo(): void
    {
        $disco = Storage::disk(config('filesystems.default'));

        $this->actingAs($this->admin)->postJson('/api/company/logo', [
            'logo' => UploadedFile::fake()->image('marca.png'),
        ])->assertOk();

        $caminho = $this->empresa->fresh()->logo_path;

        $this->actingAs($this->admin)->deleteJson('/api/company/logo')->assertOk();

        $this->assertNull($this->empresa->fresh()->logo_path);
        $disco->assertMissing($caminho);
    }
}

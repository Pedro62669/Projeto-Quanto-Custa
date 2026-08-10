<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Enums\UserRole;
use App\Models\Material;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A porta de entrada do SaaS.
 *
 * O teste que dá sentido ao arquivo é o primeiro: uma empresa recém-cadastrada
 * precisa CALCULAR no primeiro request. Sem `CostSetting`, `current()` lança
 * DomainException e a calculadora — que é o produto — devolve erro na tela
 * inicial de quem acabou de digitar a senha. Criar só `tenants` + `users`
 * entregaria uma conta quebrada, e o defeito só apareceria no primeiro clique
 * de um cliente real.
 */
class CadastroDeEmpresaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function formulario(array $overrides = []): array
    {
        return [
            'empresa' => 'Cartonagem da Ana',
            'nome' => 'Ana Silva',
            'email' => 'ana@cartonagem.test',
            'password' => 'senha-bem-longa',
            'password_confirmation' => 'senha-bem-longa',
            ...$overrides,
        ];
    }

    /* ── O provisionamento ─────────────────────────────────────────────── */

    #[Test]
    public function a_empresa_nova_consegue_calcular_no_primeiro_request(): void
    {
        $resposta = $this->postJson('/api/register', $this->formulario())->assertCreated();

        $token = $resposta->json('data.token');

        $material = Material::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $resposta->json('data.tenant.id'))
            ->firstOrFail();

        /*
         * Ponta a ponta com o token recém-emitido, sem nenhum setup adicional.
         * É o cenário exato de quem acabou de se cadastrar e clicou em
         * "calcular" — e o que prova que os custos padrão foram semeados.
         */
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/quotes/simulate', [
                'material_id' => $material->id,
                'box_model' => 'rsc',
                'width_mm' => 300,
                'height_mm' => 200,
                'depth_mm' => 150,
                'quantity' => 100,
                'waste_percent' => 10,
                'production_minutes_per_unit' => 2.5,
                'profit_margin_percent' => 30,
                'pricing_mode' => 'markup',
            ])
            ->assertOk()
            ->assertJsonPath('data.engine_version', fn ($v) => $v !== null);
    }

    #[Test]
    public function o_cadastro_cria_empresa_admin_custos_e_materiais(): void
    {
        $this->postJson('/api/register', $this->formulario())
            ->assertCreated()
            ->assertJsonPath('data.tenant.name', 'Cartonagem da Ana')
            ->assertJsonPath('data.provisionado.materiais', 4);

        $tenant = Tenant::query()->sole();
        $admin = $tenant->users()->sole();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertSame($tenant->id, $admin->tenant_id);
        $this->assertSame(1, $tenant->costSettings()->count());
        $this->assertSame(4, $tenant->materials()->count());
    }

    #[Test]
    public function o_responsavel_nasce_admin_da_empresa_e_nao_da_plataforma(): void
    {
        $this->postJson('/api/register', $this->formulario())->assertCreated();

        $admin = User::query()->sole();

        /*
         * A distinção que a Fase 1 introduziu: admin de PLATAFORMA é quem tem
         * tenant_id nulo. Um cadastro público que produzisse um deles daria a
         * qualquer pessoa da internet acesso a todas as empresas.
         */
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isPlatformAdmin());
    }

    #[Test]
    public function o_cadastro_nao_deixa_a_empresa_pela_metade_quando_falha(): void
    {
        $this->postJson('/api/register', $this->formulario())->assertCreated();

        // Segundo cadastro com o mesmo e-mail: barrado na validação, antes de
        // qualquer escrita.
        $this->postJson('/api/register', $this->formulario(['empresa' => 'Outra']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, Tenant::query()->count());
    }

    /* ── Período de teste ──────────────────────────────────────────────── */

    #[Test]
    public function a_empresa_nova_comeca_no_profissional_em_teste(): void
    {
        config(['billing.dias_de_teste' => 3]);

        $this->postJson('/api/register', $this->formulario())->assertCreated();

        $tenant = Tenant::query()->sole();

        $this->assertSame(PlanType::Pro, $tenant->plan_type);
        $this->assertSame(PlanStatus::Trialing, $tenant->plan_status);
        $this->assertSame(now()->addDays(3)->toDateString(), $tenant->trial_ends_at->toDateString());

        /*
         * A linha que decide se o fim do teste rebaixa ou bloqueia. Preenchido,
         * este campo jogaria a empresa no EnsureSubscriptionIsActive assim que o
         * teste vencesse — e quem estava avaliando seria tratado como
         * inadimplente.
         */
        $this->assertNull($tenant->subscription_ends_at);
    }

    /* ── Verificação de e-mail ─────────────────────────────────────────── */

    #[Test]
    public function o_cadastro_dispara_o_e_mail_de_confirmacao(): void
    {
        Notification::fake();

        $this->postJson('/api/register', $this->formulario())->assertCreated();

        /*
         * UMA vez, e a contagem é o ponto.
         *
         * O framework amarra SendEmailVerificationNotification ao evento
         * Registered sozinho. Registrar o mesmo listener de novo — o que parece
         * necessário quando se olha bootstrap/providers.php e não se encontra um
         * EventServiceProvider — não substitui: soma. O cliente novo receberia
         * dois e-mails de confirmação idênticos, e isso não aparece em log
         * nenhum. Ver o comentário no AppServiceProvider.
         */
        Notification::assertSentToTimes(User::query()->sole(), VerifyEmail::class, 1);
    }

    #[Test]
    public function a_conta_funciona_antes_de_o_e_mail_ser_confirmado(): void
    {
        $token = $this->postJson('/api/register', $this->formulario())
            ->assertCreated()
            ->json('data.token');

        // Mandar a pessoa para a caixa postal antes de ela conhecer o produto é
        // a forma mais eficiente de perder o cadastro que se acabou de ganhar.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.user.email_verified', false);
    }

    #[Test]
    public function o_link_assinado_confirma_o_e_mail(): void
    {
        $this->postJson('/api/register', $this->formulario())->assertCreated();

        $usuario = User::query()->sole();

        $this->get($this->linkDeVerificacao($usuario))->assertOk();

        $this->assertTrue($usuario->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function um_link_de_verificacao_sem_assinatura_nao_confirma_nada(): void
    {
        $this->postJson('/api/register', $this->formulario())->assertCreated();

        $usuario = User::query()->sole();
        $hash = sha1($usuario->getEmailForVerification());

        $this->get("/email/verificar/{$usuario->id}/{$hash}")->assertForbidden();

        $this->assertFalse($usuario->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function o_link_deixa_de_valer_quando_o_e_mail_muda(): void
    {
        $this->postJson('/api/register', $this->formulario())->assertCreated();

        $usuario = User::query()->sole();
        $link = $this->linkDeVerificacao($usuario);

        // Trocou de endereço depois de pedir a verificação: o link antigo
        // confirmaria um e-mail que ninguém provou possuir.
        $usuario->forceFill(['email' => 'outro@cartonagem.test'])->save();

        $this->get($link)->assertOk()->assertSee('não vale mais');

        $this->assertFalse($usuario->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function clicar_duas_vezes_no_link_nao_vira_erro(): void
    {
        $this->postJson('/api/register', $this->formulario())->assertCreated();

        $usuario = User::query()->sole();
        $link = $this->linkDeVerificacao($usuario);

        $this->get($link)->assertOk();
        $this->get($link)->assertOk()->assertSee('já estava confirmado');
    }

    #[Test]
    public function o_usuario_pede_o_reenvio_do_link(): void
    {
        Notification::fake();

        $token = $this->postJson('/api/register', $this->formulario())
            ->assertCreated()
            ->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/email/verification-notification')
            ->assertOk();

        Notification::assertSentToTimes(User::query()->sole(), VerifyEmail::class, 2);
    }

    /* ── Validação ─────────────────────────────────────────────────────── */

    #[Test]
    public function o_e_mail_e_unico_entre_todas_as_empresas(): void
    {
        $vizinha = Tenant::factory()->create();
        User::factory()->create(['tenant_id' => $vizinha->id, 'email' => 'ana@cartonagem.test']);

        /*
         * Único globalmente, e não por empresa: o login é só por e-mail, e dois
         * inquilinos com o mesmo endereço deixariam a autenticação sem como
         * escolher qual dos dois.
         */
        $this->postJson('/api/register', $this->formulario())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function a_senha_precisa_de_confirmacao_e_tamanho_minimo(): void
    {
        $this->postJson('/api/register', $this->formulario(['password_confirmation' => 'outra']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/register', $this->formulario([
            'password' => 'curta',
            'password_confirmation' => 'curta',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    #[Test]
    public function duas_empresas_nao_compartilham_o_mesmo_cnpj(): void
    {
        $this->postJson('/api/register', $this->formulario(['documento' => '12345678000199']))
            ->assertCreated();

        $this->postJson('/api/register', $this->formulario([
            'email' => 'outra@cartonagem.test',
            'empresa' => 'Cartonagem Beta',
            'documento' => '12345678000199',
        ]))->assertUnprocessable()->assertJsonValidationErrors('documento');
    }

    /* ── Isolamento ────────────────────────────────────────────────────── */

    #[Test]
    public function a_empresa_nova_nao_enxerga_dados_de_quem_ja_existia(): void
    {
        $vizinha = Tenant::factory()->create();
        Material::factory()->count(3)->create(['tenant_id' => $vizinha->id]);

        $token = $this->postJson('/api/register', $this->formulario())
            ->assertCreated()
            ->json('data.token');

        // Só os 4 do próprio provisionamento — o TenantScope vale desde o
        // primeiro request de uma conta recém-criada.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/materials')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    private function linkDeVerificacao(User $usuario): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $usuario->id,
            'hash' => sha1($usuario->getEmailForVerification()),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\ConviteDeRetorno;
use App\Models\EngagementEmail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reengajamento por e-mail e o descadastro em um clique.
 *
 * O risco de um cron de e-mail não é ele não enviar: é ele enviar demais. Um
 * critério que continua verdadeiro no dia seguinte transforma "sumiu há 10 dias"
 * em trinta e-mails iguais para quem tirou férias — e o endereço do remetente,
 * que é o mesmo do aviso de fatura, vai para o lixo eletrônico junto.
 */
class EngagementEmailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->create();
        Mail::fake();
    }

    private function usuarioSumidoHa(int $dias): User
    {
        return User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'last_login_at' => now()->subDays($dias),
        ]);
    }

    /* ── Quem entra na lista ───────────────────────────────────────────── */

    #[Test]
    public function o_login_registra_a_data_do_ultimo_acesso(): void
    {
        $usuario = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'email' => 'dono@cartonagem.test',
            'last_login_at' => null,
        ]);

        $this->postJson('/api/login', [
            'email' => 'dono@cartonagem.test',
            'password' => 'password',
        ])->assertOk();

        $this->assertNotNull($usuario->fresh()->last_login_at);
    }

    #[Test]
    public function o_comando_alcanca_quem_sumiu_e_ignora_quem_esta_ativo(): void
    {
        $sumido = $this->usuarioSumidoHa(20);
        $ativo = $this->usuarioSumidoHa(2);

        $this->artisan('app:send-engagement-emails')->assertSuccessful();

        Mail::assertSent(ConviteDeRetorno::class, fn ($mail) => $mail->hasTo($sumido->email));
        Mail::assertNotSent(ConviteDeRetorno::class, fn ($mail) => $mail->hasTo($ativo->email));
    }

    #[Test]
    public function quem_se_cadastrou_e_nunca_voltou_tambem_entra(): void
    {
        /*
         * O caso mais importante e o mais fácil de esquecer: quem cria a conta e
         * nunca acessa é exatamente quem a campanha precisa alcançar, e o campo
         * dele é null — um `where('last_login_at', '<', $corte)` sozinho o
         * deixaria de fora para sempre.
         */
        $usuario = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'last_login_at' => null,
            'created_at' => now()->subDays(30),
        ]);

        $this->artisan('app:send-engagement-emails')->assertSuccessful();

        Mail::assertSent(ConviteDeRetorno::class, fn ($mail) => $mail->hasTo($usuario->email));
    }

    #[Test]
    public function ninguem_recebe_dois_e_mails_em_sequencia(): void
    {
        $usuario = $this->usuarioSumidoHa(30);

        $this->artisan('app:send-engagement-emails')->assertSuccessful();

        // O cron roda todo dia, e o critério "sumiu há 30 dias" continua
        // verdadeiro amanhã. Sem o histórico, a pessoa recebe o mesmo e-mail
        // diariamente até voltar — ou até marcar como spam.
        $this->artisan('app:send-engagement-emails')->assertSuccessful();

        Mail::assertSentCount(1);
        $this->assertSame(1, EngagementEmail::query()->where('user_id', $usuario->id)->count());
    }

    #[Test]
    public function passado_o_intervalo_o_usuario_volta_a_ser_elegivel(): void
    {
        $usuario = $this->usuarioSumidoHa(30);

        EngagementEmail::create([
            'user_id' => $usuario->id,
            'tenant_id' => $this->empresa->id,
            'type' => 'inatividade',
            'sent_at' => now()->subDays(40),
        ]);

        $this->artisan('app:send-engagement-emails')->assertSuccessful();

        Mail::assertSent(ConviteDeRetorno::class, fn ($mail) => $mail->hasTo($usuario->email));
    }

    #[Test]
    public function usuario_de_empresa_suspensa_nao_recebe_convite(): void
    {
        $suspensa = Tenant::factory()->inativo()->create();
        $usuario = User::factory()->create([
            'tenant_id' => $suspensa->id,
            'last_login_at' => now()->subDays(30),
        ]);

        // Convidar para voltar a usar o que a pessoa não consegue mais acessar
        // é pior do que não mandar nada.
        $this->artisan('app:send-engagement-emails')->assertSuccessful();

        Mail::assertNotSent(ConviteDeRetorno::class, fn ($mail) => $mail->hasTo($usuario->email));
    }

    #[Test]
    public function o_admin_de_plataforma_nao_recebe_convite(): void
    {
        $nos = User::factory()->create([
            'tenant_id' => null,
            'last_login_at' => now()->subDays(60),
        ]);

        $this->artisan('app:send-engagement-emails')->assertSuccessful();

        Mail::assertNotSent(ConviteDeRetorno::class, fn ($mail) => $mail->hasTo($nos->email));
    }

    #[Test]
    public function usuario_desativado_nao_recebe_convite(): void
    {
        $usuario = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'is_active' => false,
            'last_login_at' => now()->subDays(30),
        ]);

        $this->artisan('app:send-engagement-emails')->assertSuccessful();

        Mail::assertNotSent(ConviteDeRetorno::class, fn ($mail) => $mail->hasTo($usuario->email));
    }

    #[Test]
    public function a_simulacao_nao_envia_nem_registra(): void
    {
        $this->usuarioSumidoHa(30);

        $this->artisan('app:send-engagement-emails', ['--dry-run' => true])->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertDatabaseCount('engagement_emails', 0);
    }

    /* ── Descadastro (LGPD art. 18, §2º) ───────────────────────────────── */

    #[Test]
    public function o_link_assinado_descadastra_sem_exigir_login(): void
    {
        $usuario = $this->usuarioSumidoHa(30);

        /*
         * Sem login de propósito: exigir autenticação para sair de uma lista de
         * e-mails transformaria "um clique" em "lembre a senha, entre no
         * sistema, ache a configuração" — e quem quer sair é justamente quem não
         * quer entrar.
         */
        $this->get(URL::signedRoute('engajamento.descadastro', ['user' => $usuario->id]))
            ->assertOk()
            ->assertSee($usuario->email);

        $this->assertNotNull($usuario->fresh()->marketing_opt_out_at);
    }

    #[Test]
    public function um_link_sem_assinatura_nao_descadastra_ninguem(): void
    {
        $usuario = $this->usuarioSumidoHa(30);

        // Sem a assinatura, o id na URL faria do endereço um botão anônimo de
        // sabotagem: bastaria iterar ids para descadastrar a base inteira.
        $this->get("/descadastro/{$usuario->id}")->assertForbidden();

        $this->assertNull($usuario->fresh()->marketing_opt_out_at);
    }

    #[Test]
    public function quem_se_descadastrou_para_de_receber(): void
    {
        $usuario = $this->usuarioSumidoHa(30);

        $this->get(URL::signedRoute('engajamento.descadastro', ['user' => $usuario->id]))->assertOk();

        $this->artisan('app:send-engagement-emails')->assertSuccessful();

        Mail::assertNothingSent();
    }

    #[Test]
    public function clicar_duas_vezes_preserva_a_data_do_primeiro_descadastro(): void
    {
        $usuario = $this->usuarioSumidoHa(30);
        $link = URL::signedRoute('engajamento.descadastro', ['user' => $usuario->id]);

        $this->get($link)->assertOk();
        $primeira = $usuario->fresh()->marketing_opt_out_at;

        $this->travel(2)->days();
        $this->get($link)->assertOk();

        // A data prova QUANDO o titular se opôs — é o que se apresenta se
        // alguém questionar. Reescrevê-la a cada clique apagaria a prova.
        $this->assertTrue($primeira->equalTo($usuario->fresh()->marketing_opt_out_at));
    }
}

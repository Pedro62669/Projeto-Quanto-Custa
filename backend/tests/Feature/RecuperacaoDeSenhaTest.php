<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Esqueci minha senha".
 *
 * Duas rotas públicas, e é isso que define o foco dos testes: quem chama não
 * está autenticado, então toda a superfície é anônima. Metade dos casos aqui não
 * verifica se a senha muda — verifica o que a resposta NÃO conta a um estranho.
 */
class RecuperacaoDeSenhaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->empresa = Tenant::factory()->create();
    }

    private function usuario(array $overrides = []): User
    {
        return User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'email' => 'dono@cartonagem.test',
            'password' => 'senha-antiga-123',
            ...$overrides,
        ]);
    }

    /** Extrai o token real da notificação, como o usuário faria pelo e-mail. */
    private function tokenDe(User $usuario): string
    {
        $token = null;

        Notification::assertSentTo($usuario, ResetPassword::class, function ($notificacao) use (&$token) {
            $token = $notificacao->token;

            return true;
        });

        return (string) $token;
    }

    /* ── Pedir o link ──────────────────────────────────────────────────── */

    #[Test]
    public function o_pedido_dispara_o_e_mail_com_o_link(): void
    {
        $usuario = $this->usuario();

        $this->postJson('/api/password/email', ['email' => $usuario->email])
            ->assertOk();

        Notification::assertSentTo($usuario, ResetPassword::class);
    }

    #[Test]
    public function a_resposta_e_a_mesma_para_e_mail_que_existe_e_que_nao_existe(): void
    {
        $this->usuario();

        $existente = $this->postJson('/api/password/email', ['email' => 'dono@cartonagem.test'])
            ->assertOk()
            ->json('message');

        $inexistente = $this->postJson('/api/password/email', ['email' => 'ninguem@lugar.test'])
            ->assertOk()
            ->json('message');

        /*
         * O ponto do arquivo. Distinguir os dois casos transformaria a rota num
         * verificador de contas: um estranho descobriria, um e-mail por vez,
         * quem é cliente do sistema. Mesma decisão que o AuthController toma na
         * mensagem de credencial inválida.
         */
        $this->assertSame($existente, $inexistente);
    }

    #[Test]
    public function conta_desativada_nao_recebe_link(): void
    {
        $usuario = $this->usuario(['is_active' => false]);

        // Resposta idêntica — a diferença não pode vazar —, mas nada é enviado:
        // convidar de volta quem o login vai barrar seria só confundir.
        $this->postJson('/api/password/email', ['email' => $usuario->email])->assertOk();

        Notification::assertNotSentTo($usuario, ResetPassword::class);
    }

    #[Test]
    public function e_mail_mal_formado_e_recusado(): void
    {
        $this->postJson('/api/password/email', ['email' => 'nao-e-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    /* ── Redefinir ─────────────────────────────────────────────────────── */

    #[Test]
    public function o_token_do_e_mail_troca_a_senha(): void
    {
        $usuario = $this->usuario();

        $this->postJson('/api/password/email', ['email' => $usuario->email])->assertOk();

        $this->postJson('/api/password/reset', [
            'token' => $this->tokenDe($usuario),
            'email' => $usuario->email,
            'password' => 'senha-nova-longa',
            'password_confirmation' => 'senha-nova-longa',
        ])->assertOk();

        $this->assertTrue(Hash::check('senha-nova-longa', $usuario->fresh()->password));

        // E a senha nova precisa realmente abrir a porta.
        $this->postJson('/api/login', [
            'email' => $usuario->email,
            'password' => 'senha-nova-longa',
        ])->assertOk();
    }

    #[Test]
    public function redefinir_derruba_todas_as_sessoes_abertas(): void
    {
        $usuario = $this->usuario();
        $usuario->createToken('celular');
        $usuario->createToken('notebook');

        $this->postJson('/api/password/email', ['email' => $usuario->email])->assertOk();

        $this->postJson('/api/password/reset', [
            'token' => $this->tokenDe($usuario),
            'email' => $usuario->email,
            'password' => 'senha-nova-longa',
            'password_confirmation' => 'senha-nova-longa',
        ])->assertOk();

        /*
         * Quem redefine a senha quase sempre o faz porque perdeu o controle
         * dela. Manter os tokens vivos deixaria a sessão de quem a roubou
         * funcionando por mais sete dias, e a redefinição viraria teatro.
         */
        $this->assertSame(0, $usuario->tokens()->count());
    }

    #[Test]
    public function o_token_so_serve_uma_vez(): void
    {
        $usuario = $this->usuario();

        $this->postJson('/api/password/email', ['email' => $usuario->email])->assertOk();
        $token = $this->tokenDe($usuario);

        $payload = [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'senha-nova-longa',
            'password_confirmation' => 'senha-nova-longa',
        ];

        $this->postJson('/api/password/reset', $payload)->assertOk();

        // Reuso: um link vazado da caixa postal não pode servir de chave-mestra.
        $this->postJson('/api/password/reset', [...$payload, 'password' => 'terceira-senha-123',
            'password_confirmation' => 'terceira-senha-123'])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'token_invalido');

        $this->assertTrue(Hash::check('senha-nova-longa', $usuario->fresh()->password));
    }

    #[Test]
    public function um_token_inventado_nao_troca_a_senha(): void
    {
        $usuario = $this->usuario();

        $this->postJson('/api/password/reset', [
            'token' => 'token-inventado-por-um-atacante',
            'email' => $usuario->email,
            'password' => 'senha-do-atacante',
            'password_confirmation' => 'senha-do-atacante',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'token_invalido');

        $this->assertTrue(Hash::check('senha-antiga-123', $usuario->fresh()->password));
    }

    #[Test]
    public function o_token_de_um_usuario_nao_serve_para_outro(): void
    {
        $vitima = $this->usuario();

        $atacante = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'email' => 'atacante@cartonagem.test',
        ]);

        $this->postJson('/api/password/email', ['email' => $atacante->email])->assertOk();

        // Token legítimo, e-mail de outra pessoa: o broker amarra os dois.
        $this->postJson('/api/password/reset', [
            'token' => $this->tokenDe($atacante),
            'email' => $vitima->email,
            'password' => 'senha-do-atacante',
            'password_confirmation' => 'senha-do-atacante',
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check('senha-antiga-123', $vitima->fresh()->password));
    }

    #[Test]
    public function o_token_expirado_e_recusado(): void
    {
        $usuario = $this->usuario();

        $this->postJson('/api/password/email', ['email' => $usuario->email])->assertOk();
        $token = $this->tokenDe($usuario);

        // `auth.passwords.users.expire` é 60 minutos.
        $this->travel(61)->minutes();

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'senha-nova-longa',
            'password_confirmation' => 'senha-nova-longa',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'token_invalido');
    }

    #[Test]
    public function a_senha_nova_exige_confirmacao_e_tamanho_minimo(): void
    {
        $usuario = $this->usuario();

        $this->postJson('/api/password/email', ['email' => $usuario->email])->assertOk();
        $token = $this->tokenDe($usuario);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'senha-nova-longa',
            'password_confirmation' => 'diferente',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'curta',
            'password_confirmation' => 'curta',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    /* ── O link ────────────────────────────────────────────────────────── */

    #[Test]
    public function o_link_do_e_mail_aponta_para_o_frontend(): void
    {
        config(['app.frontend_url' => 'https://app.quantocusta.test']);

        $usuario = $this->usuario();

        $this->postJson('/api/password/email', ['email' => $usuario->email])->assertOk();

        Notification::assertSentTo($usuario, ResetPassword::class, function ($notificacao) use ($usuario) {
            $corpo = $notificacao->toMail($usuario)->actionUrl;

            /*
             * A arquitetura é headless: o Laravel não serve tela nenhuma. O link
             * padrão do framework monta a URL a partir de uma rota web
             * `password.reset` que aqui não existe — sem a sobrescrita no
             * AppServiceProvider, o e-mail sairia quebrado ou nem sairia.
             */
            return str_starts_with($corpo, 'https://app.quantocusta.test/redefinir-senha')
                && str_contains($corpo, urlencode($usuario->email));
        });
    }

    #[Test]
    public function o_broker_impede_pedidos_repetidos_em_sequencia(): void
    {
        $usuario = $this->usuario();

        $this->postJson('/api/password/email', ['email' => $usuario->email])->assertOk();
        $this->postJson('/api/password/email', ['email' => $usuario->email])->assertOk();

        /*
         * `auth.passwords.users.throttle` é 60s. A resposta continua neutra — a
         * pessoa não precisa saber que foi barrada —, mas o segundo e-mail não
         * sai: sem isso, a rota vira ferramenta de inundar a caixa postal de
         * terceiros.
         */
        Notification::assertSentToTimes($usuario, ResetPassword::class, 1);
        $this->assertSame(Password::RESET_THROTTLED, Password::sendResetLink(['email' => $usuario->email]));
    }
}

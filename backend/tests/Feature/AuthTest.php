<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O throttle é por e-mail+IP e persiste entre testes no mesmo store.
        RateLimiter::clear('valido@teste.local|127.0.0.1');
    }

    private function user(array $overrides = []): User
    {
        return User::factory()->create([
            'email' => 'valido@teste.local',
            'password' => 'senha-correta-123',
            ...$overrides,
        ]);
    }

    #[Test]
    public function autentica_e_devolve_token(): void
    {
        $user = $this->user();

        $response = $this->postJson('/api/login', [
            'email' => 'valido@teste.local',
            'password' => 'senha-correta-123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'user')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role']]]);

        // O token precisa realmente abrir as portas da API.
        $token = $response->json('data.token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    #[Test]
    public function a_senha_nunca_volta_na_resposta(): void
    {
        $this->user();

        $response = $this->postJson('/api/login', [
            'email' => 'valido@teste.local',
            'password' => 'senha-correta-123',
        ]);

        $this->assertStringNotContainsString('senha-correta-123', $response->getContent());
        $this->assertArrayNotHasKey('password', $response->json('data.user'));
    }

    #[Test]
    public function a_mensagem_de_erro_nao_revela_se_o_email_existe(): void
    {
        $this->user();

        $senhaErrada = $this->postJson('/api/login', [
            'email' => 'valido@teste.local',
            'password' => 'errada',
        ]);

        $emailInexistente = $this->postJson('/api/login', [
            'email' => 'fantasma@teste.local',
            'password' => 'qualquer',
        ]);

        $senhaErrada->assertUnprocessable();
        $emailInexistente->assertUnprocessable();

        // Mensagens idênticas: caso contrário o endpoint vira um oráculo que
        // enumera os usuários cadastrados.
        $this->assertSame(
            $senhaErrada->json('errors.email'),
            $emailInexistente->json('errors.email'),
        );
    }

    #[Test]
    public function usuario_desativado_nao_entra(): void
    {
        $this->user(['is_active' => false]);

        $this->postJson('/api/login', [
            'email' => 'valido@teste.local',
            'password' => 'senha-correta-123',
        ])->assertUnprocessable();
    }

    #[Test]
    public function o_login_e_limitado_por_tentativas(): void
    {
        $this->user();

        // 5 tentativas permitidas; a 6ª deve ser barrada.
        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/login', [
                'email' => 'valido@teste.local',
                'password' => 'errada',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/login', [
            'email' => 'valido@teste.local',
            'password' => 'errada',
        ])->assertStatus(429);
    }

    #[Test]
    public function o_acerto_da_senha_zera_o_contador_de_tentativas(): void
    {
        $this->user();

        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/login', ['email' => 'valido@teste.local', 'password' => 'errada']);
        }

        $this->postJson('/api/login', [
            'email' => 'valido@teste.local',
            'password' => 'senha-correta-123',
        ])->assertOk();

        // Sem o clear(), um usuário legítimo que errou a senha algumas vezes
        // ficaria bloqueado mesmo depois de acertar.
        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/login', ['email' => 'valido@teste.local', 'password' => 'errada'])
                ->assertUnprocessable();
        }
    }

    #[Test]
    public function o_logout_revoga_apenas_o_token_atual(): void
    {
        $user = $this->user();

        $notebook = $user->createToken('notebook')->plainTextToken;
        $celular = $user->createToken('celular')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$notebook}")
            ->postJson('/api/logout')
            ->assertNoContent();

        // O guard resolvido permanece em memória entre requisições do MESMO
        // teste (em produção cada request é um processo novo). Sem descartá-lo,
        // a asserção seguinte passaria por um usuário já autenticado em cache,
        // sem chegar a reconsultar o token — e não provaria nada.
        $this->app['auth']->forgetGuards();

        // O token usado no logout morre...
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->withHeader('Authorization', "Bearer {$notebook}")
            ->getJson('/api/me')
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        // ...mas a sessão do outro dispositivo continua de pé.
        $this->withHeader('Authorization', "Bearer {$celular}")
            ->getJson('/api/me')
            ->assertOk();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Autenticação por token (Sanctum).
 *
 * Tokens Bearer, e não sessão por cookie: o frontend Next.js roda em outra
 * origem, e a modalidade stateful do Sanctum exigiria cookies same-site,
 * domínio compartilhado e o dance de CSRF. Token é o encaixe natural de uma
 * arquitetura headless.
 */
class AuthController extends Controller
{
    /** Tentativas de login permitidas por minuto, por e-mail + IP. */
    private const MAX_ATTEMPTS = 5;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        // Throttle por e-mail+IP: sem isto o endpoint é um oráculo de força
        // bruta. A chave inclui o IP para que um atacante não consiga travar
        // a conta de terceiros só martelando o e-mail deles.
        $throttleKey = mb_strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Muitas tentativas. Tente novamente em '
                    .RateLimiter::availableIn($throttleKey).' segundos.',
            ])->status(JsonResponse::HTTP_TOO_MANY_REQUESTS);
        }

        $user = User::where('email', $credentials['email'])->first();

        // Hash::check mesmo com usuário inexistente seria ideal para igualar o
        // tempo de resposta; aqui o throttle já limita a exploração de timing.
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey);

            // Mensagem genérica: distinguir "e-mail não existe" de "senha
            // errada" entregaria de graça a lista de usuários do sistema.
            throw ValidationException::withMessages([
                'email' => 'Credenciais inválidas.',
            ]);
        }

        if (! $user->is_active) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'email' => 'Esta conta está desativada.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        /*
         * Marca o acesso.
         *
         * forceFill em vez de update() porque `last_login_at` está fora do
         * $fillable de propósito: é rastro do sistema, não campo de formulário.
         *
         * Redundante com access_logs à primeira vista — mas o expurgo da LGPD
         * apaga registros com mais de 6 meses, e quem sumiu há sete é justamente
         * quem a campanha de reengajamento procura. Ver a migration.
         */
        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken(
            $credentials['device_name'] ?? 'web',
            expiresAt: now()->addDays(7),
        );

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                ],
            ],
        ]);
    }

    /**
     * Revoga apenas o token da requisição atual — sair no notebook não pode
     * derrubar a sessão do celular.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}

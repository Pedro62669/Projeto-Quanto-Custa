<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * Suporte aos usuários das empresas assinantes.
 *
 * Duas ações, e a fronteira entre elas é o ponto do arquivo: o operador da
 * plataforma pode INTERROMPER um acesso e pode CONVIDAR o titular a definir uma
 * senha nova. Não pode escolher a senha por ele.
 *
 * A diferença parece formal e não é. Quem define a senha de alguém consegue
 * entrar como essa pessoa, e a partir daí nenhum registro do sistema distingue
 * o titular do operador — "quem aprovou este orçamento" deixa de ter resposta
 * confiável. O mesmo raciocínio que fez o AccessLog ser assinado.
 */
class PlatformUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $usuarios = User::query()
            ->with('tenant:id,name,plan_type,plan_status,is_active')
            ->whereNotNull('tenant_id')
            ->when($request->filled('search'), fn ($q) => $q->where(
                fn ($sub) => $sub
                    ->whereLike('name', "%{$request->string('search')}%", caseSensitive: false)
                    ->orWhereLike('email', "%{$request->string('search')}%", caseSensitive: false),
            ))
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))
            ->when($request->boolean('inativos'), fn ($q) => $q->where('is_active', false))
            ->orderByDesc('last_login_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($usuarios);
    }

    /**
     * Encerra todas as sessões do usuário.
     *
     * O uso real: cliente ligou dizendo que o notebook foi roubado. Revogar os
     * tokens resolve na hora, sem depender de ele lembrar a senha para trocá-la.
     */
    public function forceLogout(User $user): JsonResponse
    {
        $revogados = $user->tokens()->delete();

        return response()->json([
            'message' => "Sessões encerradas ({$revogados} token(s) revogado(s)).",
        ]);
    }

    /**
     * Dispara o link de redefinição de senha para o e-mail do titular.
     *
     * Revoga as sessões junto: se a redefinição foi pedida porque a conta pode
     * estar comprometida, deixar o token do invasor vivo até ele expirar sozinho
     * anula o propósito da redefinição.
     */
    public function sendPasswordReset(User $user): JsonResponse
    {
        $user->tokens()->delete();

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'Não foi possível enviar o link agora. Sessões foram encerradas mesmo assim.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => "Link de redefinição enviado para {$user->email}. As sessões ativas foram encerradas.",
        ]);
    }

    /** Ativa ou desativa o login de um usuário específico. */
    public function toggleActive(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'ativo' => ['required', 'boolean'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        $ativo = $request->boolean('ativo');

        $user->forceFill(['is_active' => $ativo])->save();

        if (! $ativo) {
            // Desativar sem revogar deixaria o token atual funcionando até
            // expirar — o middleware de admin checa is_active, mas as rotas de
            // usuário comum não.
            $user->tokens()->delete();
        }

        return response()->json([
            'data' => $user->fresh(),
            'message' => $ativo ? 'Usuário reativado.' : 'Usuário desativado e sessões encerradas.',
        ]);
    }
}

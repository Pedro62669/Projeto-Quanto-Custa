<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reenvio do e-mail de confirmação.
 *
 * Existe porque o link da notificação padrão do Laravel é temporário — 60
 * minutos, `auth.verification.expire`. Quem se cadastra à noite e abre a caixa
 * postal na manhã seguinte encontra um link morto, e sem este endpoint o único
 * caminho seria criar outra conta.
 *
 * Autenticado: o cadastro já devolve token, então a pessoa está logada mesmo sem
 * ter confirmado nada. Reenviar só para o e-mail do PRÓPRIO usuário da sessão
 * fecha o uso como ferramenta de spam contra terceiros.
 */
class EmailVerificationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $usuario = $request->user();

        if ($usuario->hasVerifiedEmail()) {
            return response()->json(['message' => 'Este e-mail já está confirmado.']);
        }

        $usuario->sendEmailVerificationNotification();

        return response()->json([
            'message' => "Enviamos um novo link de confirmação para {$usuario->email}. "
                .'Ele vale por uma hora.',
        ]);
    }
}

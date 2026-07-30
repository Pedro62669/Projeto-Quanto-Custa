<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barreira do grupo de rotas /api/admin.
 *
 * Aplicada no roteador (e não checada dentro de cada controller) para que uma
 * rota nova criada dentro do grupo já nasça protegida — falha fechada por padrão.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin() || ! $user->is_active) {
            abort(Response::HTTP_FORBIDDEN, 'Acesso restrito a administradores.');
        }

        return $next($request);
    }
}

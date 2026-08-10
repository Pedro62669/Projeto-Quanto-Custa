<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barreira do grupo /api/platform — quem opera o SaaS.
 *
 * Existe separado de EnsureUserIsAdmin porque os dois "admin" do sistema não são
 * o mesmo cargo, e confundi-los aqui seria o vazamento mais caro do projeto.
 * `isAdmin()` é verdadeiro para o DONO DE CADA EMPRESA ASSINANTE — foi assim que
 * a Fase 1 redefiniu o papel. Proteger o painel de plataforma com ele entregaria
 * a cada cliente o faturamento da plataforma, a lista completa das empresas
 * concorrentes e o mapa de onde elas estão.
 *
 * O que distingue os dois não é o papel, é o `tenant_id` nulo. Ver
 * User::isPlatformAdmin().
 */
class EnsureUserIsPlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isPlatformAdmin() || ! $user->is_active) {
            /*
             * 404 e não 403. Um 403 confirma que a rota existe, e a existência de
             * um painel de plataforma é justamente o que não interessa anunciar
             * para um assinante curioso que resolveu adivinhar caminhos.
             */
            abort(Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}

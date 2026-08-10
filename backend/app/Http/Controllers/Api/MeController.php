<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\QuotaGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * O contexto da sessão — tudo que qualquer tela precisa saber de cara.
 *
 * Substitui o `fn ($r) => $r->user()` que a rota tinha. A troca não é enfeite:
 * toda página do sistema precisa do nome e da marca da empresa no cabeçalho, do
 * plano vigente para decidir se mostra o botão de upgrade, e do consumo das
 * cotas para avisar antes de a pessoa esbarrar no limite. Devolvendo só o
 * usuário, cada tela faria três requisições — e a de cotas, que muda a cada
 * gravação, seria refeita sem parar.
 *
 * Uma chamada, um retrato coerente: os três números saem da mesma leitura, então
 * o plano exibido e a cota exibida não podem discordar entre si.
 */
class MeController extends Controller
{
    public function __invoke(Request $request, QuotaGuard $quotas): JsonResponse
    {
        $usuario = $request->user();
        $tenant = $usuario->tenant;

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $usuario->id,
                    'name' => $usuario->name,
                    'email' => $usuario->email,
                    'role' => $usuario->role->value,
                    'is_admin' => $usuario->isAdmin(),

                    /*
                     * O frontend precisa saber para exibir o aviso de confirmação
                     * pendente — e porque é ele que barra o checkout.
                     */
                    'email_verified' => $usuario->hasVerifiedEmail(),
                    'last_login_at' => $usuario->last_login_at?->toIso8601String(),
                ],

                /*
                 * Nulo para o admin de plataforma, que não pertence a empresa
                 * nenhuma. O frontend usa esse nulo para decidir entre a
                 * interface do assinante e a de operação do SaaS — é o mesmo
                 * `tenant_id` nulo que separa os dois no servidor.
                 */
                'tenant' => $tenant === null ? null : [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'legal_name' => $tenant->legal_name,
                    'city' => $tenant->city,
                    'state' => $tenant->state,
                    'logo_url' => $tenant->logo_path === null ? null : url('/api/company/logo'),
                    'is_active' => $tenant->is_active,
                    'acesso_liberado' => $tenant->acessoLiberado(),
                ],

                'plano' => $tenant === null ? null : [
                    // Vigente, não contratado: é ele que manda nas cotas abaixo.
                    'tipo' => $tenant->planoVigente()->value,
                    'rotulo' => $tenant->planoVigente()->label(),
                    'contratado' => $tenant->plan_type->value,
                    'situacao' => $tenant->plan_status->value,
                    'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                    'subscription_ends_at' => $tenant->subscription_ends_at?->toIso8601String(),
                ],

                'cotas' => $tenant === null ? null : $quotas->resumo($tenant),
            ],
        ]);
    }
}

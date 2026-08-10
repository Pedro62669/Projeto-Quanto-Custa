<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CadastrarEmpresaRequest;
use App\Services\Billing\QuotaGuard;
use App\Services\Onboarding\ProvisionaEmpresa;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;

/**
 * A porta de entrada do SaaS.
 *
 * Devolve token já autenticado, e não uma tela de "confira seu e-mail para
 * continuar". A verificação sai por e-mail no mesmo instante, mas não trava
 * nada: quem acabou de digitar a senha quer ver o produto, e mandá-lo para a
 * caixa postal antes de conhecer a ferramenta é a forma mais eficiente de perder
 * o cadastro que se acabou de ganhar.
 *
 * A verificação tem duas consequências reais e nenhuma punitiva — checkout exige
 * e-mail confirmado (não se cobra de quem não se consegue alcançar) e o e-mail
 * de reengajamento pula quem não confirmou. Ver SubscriptionController::checkout.
 */
class RegisterController extends Controller
{
    public function __invoke(
        CadastrarEmpresaRequest $request,
        ProvisionaEmpresa $provisiona,
        QuotaGuard $quotas,
    ): JsonResponse {
        $dados = $request->validated();

        $tenant = $provisiona->executar(
            nomeDaEmpresa: $dados['empresa'],
            nomeDoResponsavel: $dados['nome'],
            email: $dados['email'],
            senha: $dados['password'],
            documento: $dados['documento'] ?? null,
        );

        $admin = $tenant->users()->sole();

        // Dispara o e-mail de verificação pelo listener padrão do Laravel.
        event(new Registered($admin));

        $token = $admin->createToken('web', expiresAt: now()->addDays(7));

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'user' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role->value,
                    'email_verified' => false,
                ],
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'plan_type' => $tenant->plan_type->value,
                    'plan_status' => $tenant->plan_status->value,
                    'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                ],
                'cotas' => $quotas->resumo($tenant),

                /*
                 * O que já veio pronto. A tela de boas-vindas usa isto para
                 * dizer "você já tem 4 materiais e seus custos padrão" em vez de
                 * jogar a pessoa numa calculadora que ela acha que está vazia —
                 * e para lembrar que esses números são chutes plausíveis, não os
                 * custos dela.
                 */
                'provisionado' => [
                    'materiais' => $tenant->materials()->count(),
                    'custos_padrao' => true,
                    'aviso' => 'Cadastramos matérias-primas e custos de exemplo para você começar. '
                        .'Revise-os antes do primeiro orçamento de verdade.',
                ],
            ],
        ], JsonResponse::HTTP_CREATED);
    }
}

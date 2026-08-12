<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelarAssinaturaRequest;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Services\Billing\QuotaGuard;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * A assinatura vista pelo próprio assinante.
 *
 * Não confundir com o painel de plataforma (/api/platform): aqui a empresa só
 * enxerga a própria assinatura, e o vínculo vem do usuário autenticado — nunca
 * de um id vindo da requisição, que seria IDOR na veia.
 */
class SubscriptionController extends Controller
{
    /**
     * Situação do plano e consumo das cotas.
     *
     * O consumo vai junto de propósito: a tela que mostra "faça upgrade" é a
     * mesma que precisa mostrar POR QUE, e um usuário que vê "18 de 20
     * orçamentos" entende o limite antes de esbarrar nele.
     */
    public function show(Request $request, QuotaGuard $quotas): JsonResponse
    {
        $tenant = $request->user()->tenant;

        if ($tenant === null) {
            return response()->json([
                'message' => 'Usuário sem empresa vinculada não possui assinatura.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $assinatura = $tenant->assinaturaVigente();

        return response()->json([
            'data' => [
                'plano' => [
                    /*
                     * O plano VIGENTE, não o contratado. Os dois divergem entre
                     * o fim do teste e a passagem do cron — e é o vigente que
                     * manda nas cotas logo abaixo. Mostrar o contratado faria a
                     * tela prometer um limite que o servidor recusa.
                     */
                    'tipo' => $tenant->planoVigente()->value,
                    'rotulo' => $tenant->planoVigente()->label(),
                    'mensalidade' => $tenant->planoVigente()->monthlyPrice(),
                    'contratado' => $tenant->plan_type->value,
                    'situacao' => $tenant->plan_status->value,
                    'situacao_rotulo' => $tenant->plan_status->label(),
                ],
                'acesso_liberado' => $tenant->acessoLiberado(),
                'em_teste' => $tenant->plan_status === PlanStatus::Trialing
                    && $tenant->trial_ends_at?->isFuture() === true,
                'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                'subscription_ends_at' => $tenant->subscription_ends_at?->toIso8601String(),
                'assinatura' => $assinatura === null ? null : [
                    'id' => $assinatura->id,
                    'started_at' => $assinatura->started_at->toIso8601String(),
                    'current_period_ends_at' => $assinatura->current_period_ends_at?->toIso8601String(),
                    'amount' => $assinatura->amount,

                    /*
                     * O direito de arrependimento é informado, não escondido.
                     * O CDC exige que a informação seja clara e ostensiva; um
                     * botão de cancelar que não diz "você ainda tem direito ao
                     * dinheiro de volta" cumpre a letra e falha o propósito.
                     */
                    'arrependimento_disponivel' => $assinatura->dentroDoPrazoDeArrependimento(),
                    'arrependimento_ate' => $assinatura->started_at
                        ->copy()
                        ->addDays((int) config('billing.dias_de_arrependimento', 7))
                        ->toIso8601String(),
                ],
                'cotas' => $quotas->resumo($tenant),

                /*
                 * Os planos oferecidos, com preço e limites.
                 *
                 * Vão daqui, e não de uma tabela escrita na interface, porque
                 * preço de assinatura é informação do servidor. Enquanto a tela
                 * mantinha a própria lista, ela mostrou R$ 99,90 num cartão e
                 * R$ 149,90 no cabeçalho da MESMA página — o segundo vindo do
                 * `monthlyPrice()` real. Um cliente que decide pelo cartão
                 * contrata acreditando num valor que a cobrança não pratica.
                 */
                // A mesma tabela que a página pública de preços mostra. Ver
                // PlanType::catalogo() para o porquê de não estar escrita aqui.
                'planos_disponiveis' => PlanType::catalogo(),
            ],
        ]);
    }

    /**
     * Abre a sessão de pagamento e devolve a URL do gateway.
     *
     * Não cria assinatura nenhuma aqui: quem cria é o webhook de ativação,
     * depois que o dinheiro foi confirmado. Gravar antes produziria assinaturas
     * "ativas" para todo mundo que clicou em assinar e desistiu na tela do
     * cartão — e o painel de plataforma passaria a mostrar uma receita
     * recorrente que não existe.
     */
    public function checkout(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $usuario = $request->user();
        $tenant = $usuario->tenant;

        if ($tenant === null || ! $usuario->isAdmin()) {
            return response()->json([
                'message' => 'Só o administrador da empresa pode contratar um plano.',
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        /*
         * E-mail confirmado é pré-requisito, e não uma punição por não ter
         * confirmado antes: recibo, aviso de cobrança e link de segunda via vão
         * todos por e-mail. Cobrar de alguém que não conseguimos alcançar
         * produz, na melhor das hipóteses, um estorno; na pior, uma contestação
         * de cartão.
         */
        if (! $usuario->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Confirme seu e-mail antes de assinar — é para lá que vão o recibo '
                    .'e os avisos de cobrança. Reenviamos o link quando você quiser.',
                'error' => 'email_nao_verificado',
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        $dados = $request->validate([
            'plan_type' => ['required', Rule::enum(PlanType::class)],
        ]);

        $plano = PlanType::from($dados['plan_type']);

        if (! $plano->isPaid()) {
            return response()->json([
                'message' => 'O plano gratuito não passa pelo checkout. '
                    .'Para voltar a ele, cancele a assinatura atual.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sessao = $gateway->criaCheckout($tenant, $plano);

        return response()->json(['data' => $sessao->toArray()]);
    }

    /**
     * Cancelamento, com estorno automático dentro do prazo do CDC.
     *
     * A regra inteira mora no SubscriptionManager — este método só traduz o
     * resultado em HTTP. Ver lá o porquê de o acesso terminar hoje quando há
     * estorno e só no fim do ciclo quando não há.
     */
    public function cancel(CancelarAssinaturaRequest $request, SubscriptionManager $manager): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $assinatura = $tenant->assinaturaVigente();

        if ($assinatura === null) {
            return response()->json([
                'message' => 'Não há assinatura ativa para cancelar.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $resultado = $manager->cancelar($assinatura);
        } catch (RuntimeException $e) {
            /*
             * 422 e não 500: estorno recusado pelo gateway é um desfecho de
             * negócio, com mensagem que o usuário consegue agir sobre. A
             * assinatura continua de pé — está dito na mensagem.
             */
            return response()->json([
                'message' => $e->getMessage(),
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => $resultado->toArray()]);
    }
}

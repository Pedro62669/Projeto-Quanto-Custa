<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta a ESCRITA de quem não tem assinatura em dia.
 *
 * Separado do CheckTenantLimits de propósito. A cota é sobre "quanto"; isto é
 * sobre "se". Deixar a validade da assinatura dentro do middleware de cota
 * fecharia só as três rotas que têm cota — e uma empresa vencida continuaria
 * lançando transações, aprovando orçamentos e emitindo PDF por todas as outras.
 * Aplicado ao grupo autenticado inteiro, uma rota nova nasce coberta.
 *
 * LEITURA NUNCA É BLOQUEADA, e essa é a decisão de fundo. O assinante
 * inadimplente continua enxergando e exportando o que é dele — orçamentos,
 * livro caixa, fichas técnicas. Reter dado de titular como alavanca de cobrança
 * esbarra no direito de acesso da LGPD (art. 18, I e II) e, na prática, é a
 * maneira mais rápida de transformar uma fatura atrasada numa reclamação
 * pública. Cobra-se tirando o que é NOVO, não o que já é dele.
 *
 * ## Quem nunca assinou cai aqui também
 *
 * Desde que o plano gratuito saiu do produto, a conta recém-criada não tem plano
 * — e este middleware é o que a mantém em modo somente-leitura até o pagamento
 * entrar. A pessoa entra, navega, vê a calculadora com o exemplo pronto e não
 * grava nada. É a mesma porta de sempre; o que mudou foi Tenant::acessoLiberado()
 * parar de deixar passar quem nunca pagou.
 *
 * A mensagem, porém, precisa ser outra: dizer "sua assinatura está vencida" a
 * quem nunca teve assinatura manda a pessoa procurar uma fatura que não existe.
 */
class EnsureSubscriptionIsActive
{
    /**
     * Escritas que continuam liberadas mesmo sem assinatura em dia.
     *
     * Duas famílias, e as duas são inegociáveis.
     *
     * ## Os caminhos de SAÍDA
     *
     * Bloquear o cancelamento de quem está vencido prenderia a pessoa numa
     * assinatura que ela não consegue nem encerrar, e bloquear a exclusão de
     * conta violaria o direito ao esquecimento — que não depende de estar em dia
     * com o pagamento.
     *
     * ## Os caminhos de ENTRADA
     *
     * `checkout` e o reenvio do e-mail de confirmação. Sem eles a trava vira um
     * beco sem saída: desde que a conta passou a nascer SEM PLANO, ela cai neste
     * middleware desde o primeiro minuto — e o checkout é POST, e o checkout
     * EXIGE e-mail confirmado, e o reenvio da confirmação também é POST.
     * Fechar os dois deixaria a pessoa numa conta que nunca consegue pagar,
     * porque o único jeito de pagar estava atrás da trava de quem não pagou.
     *
     * Isso não abre nada: nenhuma das duas grava dado de negócio. Uma manda um
     * e-mail para o endereço que já está na conta, a outra abre sessão no
     * gateway. Quem cria a assinatura continua sendo o webhook, depois de o
     * dinheiro entrar.
     */
    private const LIBERADAS = [
        'api/logout',
        'api/account',
        'api/subscriptions/cancel',
        'api/subscriptions/checkout',
        'api/email/verification-notification',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $escrita = in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        if (! $escrita || in_array($request->path(), self::LIBERADAS, true)) {
            return $next($request);
        }

        $tenant = $request->user()?->tenant;

        // Sem empresa é o admin de plataforma: não tem assinatura para vencer.
        if ($tenant === null || $tenant->acessoLiberado()) {
            return $next($request);
        }

        return response()->json([
            'message' => $this->recado($tenant),
            'error' => $tenant->semPlano() ? 'assinatura_necessaria' : 'assinatura_expirada',
            'plano' => $tenant->plan_type?->value,
            'subscription_ends_at' => $tenant->subscription_ends_at?->toIso8601String(),
        ], JsonResponse::HTTP_FORBIDDEN);
    }

    /**
     * Três motivos diferentes para a mesma porta fechada.
     *
     * Escrever um texto só economizaria linhas e custaria suporte: quem nunca
     * assinou iria procurar uma fatura em atraso, e quem foi suspenso por decisão
     * administrativa tentaria pagar de novo uma assinatura que já está paga.
     */
    private function recado(Tenant $tenant): string
    {
        if (! $tenant->is_active) {
            return 'Esta conta está suspensa. Fale com o suporte.';
        }

        if ($tenant->semPlano()) {
            return 'Escolha um plano para começar a usar. Enquanto isso a conta fica em '
                .'modo demonstração: você navega e vê a calculadora, mas nada é gravado.';
        }

        return 'Sua assinatura está vencida. Regularize o pagamento para voltar a criar e '
            .'editar registros. Todos os seus dados continuam acessíveis e podem ser exportados.';
    }
}

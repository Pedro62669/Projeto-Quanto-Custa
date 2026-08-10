<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta a ESCRITA de quem está com a assinatura vencida ou a conta suspensa.
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
 */
class EnsureSubscriptionIsActive
{
    /**
     * Escritas que continuam liberadas mesmo com a assinatura vencida.
     *
     * Todas têm a mesma justificativa: são os caminhos de SAÍDA. Bloquear o
     * cancelamento de quem está vencido prenderia a pessoa numa assinatura que
     * ela não consegue nem encerrar, e bloquear a exclusão de conta violaria o
     * direito ao esquecimento — que não depende de estar em dia com o pagamento.
     */
    private const LIBERADAS = [
        'api/logout',
        'api/account',
        'api/subscriptions/cancel',
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
            'message' => $tenant->is_active
                ? 'Sua assinatura está vencida. Regularize o pagamento para voltar a criar e editar registros. '
                    .'Todos os seus dados continuam acessíveis e podem ser exportados.'
                : 'Esta conta está suspensa. Fale com o suporte.',
            'error' => 'assinatura_expirada',
            'plano' => $tenant->plan_type->value,
            'subscription_ends_at' => $tenant->subscription_ends_at?->toIso8601String(),
        ], JsonResponse::HTTP_FORBIDDEN);
    }
}

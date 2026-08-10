<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TenantQuota;
use App\Services\Billing\QuotaGuard;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trava de cota do plano.
 *
 * O recurso vem por PARÂMETRO da rota (`quota:materials`), não da inspeção da
 * URI. Adivinhar pelo caminho parece mais automático, mas falha do jeito mais
 * perigoso: renomear `/api/clients` para `/api/customers` desligaria a cota em
 * silêncio, sem erro, sem teste vermelho — a receita simplesmente pararia de ser
 * cobrada e ninguém saberia por meses. Com parâmetro, uma rota nova sem cota é
 * uma omissão visível na linha da rota.
 *
 * Só age em POST. Editar e apagar continuam liberados de propósito: quem estourou
 * a cota precisa poder arrumar a casa (desativar um material, corrigir um
 * cadastro) para caber nela de novo. Uma trava que impede o próprio conserto
 * transforma o upgrade em única saída — e isso é coerção, não plano.
 *
 * Cuida do "quanto". O "se" — assinatura vencida, conta suspensa — é do
 * EnsureSubscriptionIsActive.
 */
class CheckTenantLimits
{
    public function handle(Request $request, Closure $next, string $recurso): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $user = $request->user();
        $tenant = $user?->tenant;

        /*
         * Sem empresa não há cota: é o admin de plataforma. Ele não cria
         * material nem orçamento no dia a dia, mas se criar (suporte, migração),
         * não existe plano para consultar.
         */
        if ($tenant === null) {
            return $next($request);
        }

        /*
         * Assinatura vencida NÃO é tratada aqui — é do
         * EnsureSubscriptionIsActive, aplicado ao grupo inteiro. Ver lá o motivo
         * de "quanto" e "se" serem middlewares diferentes.
         */
        $quota = TenantQuota::tryFrom($recurso);

        if ($quota === null) {
            /*
             * Recurso escrito errado na definição da rota. Estourar é
             * intencional: o contrário seria liberar a passagem para uma cota
             * que ninguém checa — exatamente a falha silenciosa que o parâmetro
             * explícito veio evitar.
             */
            throw new \InvalidArgumentException("Cota desconhecida: {$recurso}.");
        }

        $guarda = app(QuotaGuard::class);

        if ($guarda->atingiu($tenant, $quota)) {
            $limite = (int) $guarda->limite($tenant, $quota);

            return response()->json([
                'message' => $quota->mensagemDeLimite($limite),
                'error' => 'limite_atingido',
                'quota' => [
                    'recurso' => $quota->value,
                    'rotulo' => $quota->label(),
                    'limite' => $limite,
                    'usado' => $guarda->consumo($tenant, $quota),
                    'mensal' => $quota->eMensal(),
                    'plano_atual' => $tenant->plan_type->value,
                ],
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}

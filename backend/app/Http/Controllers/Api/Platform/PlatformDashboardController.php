<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Painel de quem opera o SaaS.
 *
 * Mora em /api/platform e NÃO em /api/admin. O documento da fase pedia
 * `/api/admin/dashboard`, mas aquele prefixo é guardado por EnsureUserIsAdmin,
 * que passa para o dono de qualquer empresa assinante — a Fase 1 redefiniu
 * "admin" como dono de UMA empresa. Publicar estes números lá entregaria a cada
 * cliente o faturamento da plataforma e a lista das empresas concorrentes.
 */
class PlatformDashboardController extends Controller
{
    public function __invoke(Request $request, PlatformMetrics $metrics): JsonResponse
    {
        $referencia = $request->filled('mes')
            ? Carbon::parse($request->string('mes')->toString())
            : null;

        return response()->json(['data' => $metrics->all($referencia)]);
    }
}

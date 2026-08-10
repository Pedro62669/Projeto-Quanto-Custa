<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinancialEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Painel financeiro do mês.
 *
 * GET e nada é gravado: é leitura agregada. O período vem por query string com
 * o mês corrente como padrão — abrir o painel é sempre perguntar "como está
 * este mês", e obrigar a escolher a data antes de ver qualquer número inverte
 * a ordem natural da pergunta.
 */
class FinancialDashboardController extends Controller
{
    public function __invoke(Request $request, FinancialEngine $financial): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        return response()->json([
            'data' => $financial->dashboardMetrics(
                (int) ($validated['month'] ?? now()->month),
                (int) ($validated['year'] ?? now()->year),
            ),
        ]);
    }
}

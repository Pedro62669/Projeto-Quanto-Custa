<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CostSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Custos fixos do sistema (somente admin).
 *
 * Não existe UPDATE: alterar uma tarifa cria uma NOVA versão. Editar a linha
 * vigente reescreveria a base de cálculo de orçamentos já emitidos e tornaria
 * o histórico inexplicável.
 */
class CostSettingController extends Controller
{
    /** GET /api/admin/cost-settings — histórico de versões. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CostSetting::with('author:id,name')
                ->latest('effective_from')
                ->paginate(20),
        ]);
    }

    /** GET /api/admin/cost-settings/current — versão vigente. */
    public function current(): JsonResponse
    {
        return response()->json(['data' => CostSetting::current()]);
    }

    /** POST /api/admin/cost-settings — publica uma nova versão. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'energy_tariff_per_kwh' => ['required', 'numeric', 'min:0', 'max:100'],
            'machine_hour_rate' => ['required', 'numeric', 'min:0', 'max:100000'],
            'machine_power_kw' => ['required', 'numeric', 'min:0', 'max:10000'],
            'labor_hour_rate' => ['required', 'numeric', 'min:0', 'max:100000'],

            'overhead_percent' => ['nullable', 'numeric', 'min:0', 'max:500'],
            // Abaixo de 100% porque o imposto é calculado "por dentro"
            // (preço ÷ (1 − alíquota)) — em 100% a divisão explode.
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],

            'default_profit_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'currency' => ['nullable', 'string', 'size:3'],

            // Permite agendar um reajuste para a virada do mês.
            'effective_from' => ['nullable', 'date'],
        ]);

        $setting = CostSetting::create([
            ...$validated,
            'effective_from' => $validated['effective_from'] ?? now(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $setting], JsonResponse::HTTP_CREATED);
    }
}

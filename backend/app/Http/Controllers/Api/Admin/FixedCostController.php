<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FixedCost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD das despesas fixas mensais (somente admin — ver middleware na rota).
 *
 * Sem escopo manual em lugar nenhum: o TenantScope filtra a leitura e a trait
 * carimba a escrita. Ver EquipmentController, mesma justificativa.
 */
class FixedCostController extends Controller
{
    public function index(): JsonResponse
    {
        $custos = FixedCost::query()->orderBy('name')->get();

        return response()->json([
            'data' => $custos,

            /*
             * O total acompanha a listagem porque é a única razão de a listagem
             * existir: o usuário abre esta tela para saber quanto custa o mês,
             * e obrigar o cliente a somar convidaria duas somas diferentes na
             * interface. Só o que está ATIVO entra — é o mesmo filtro que a
             * hora-empresa aplica.
             */
            'meta' => [
                'monthly_total' => round((float) FixedCost::query()->active()->sum('monthly_amount'), 2),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $custo = FixedCost::create($this->validated($request));

        return response()->json(['data' => $custo], JsonResponse::HTTP_CREATED);
    }

    public function show(FixedCost $fixedCost): JsonResponse
    {
        return response()->json(['data' => $fixedCost]);
    }

    public function update(Request $request, FixedCost $fixedCost): JsonResponse
    {
        $fixedCost->update($this->validated($request, $fixedCost));

        return response()->json(['data' => $fixedCost->fresh()]);
    }

    public function destroy(FixedCost $fixedCost): JsonResponse
    {
        $fixedCost->delete();

        return response()->json(['message' => 'Despesa removida do custo fixo mensal.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?FixedCost $fixedCost = null): array
    {
        $required = $fixedCost ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],

            // min:0 e não min:0.01: uma linha zerada é legítima (o usuário
            // cadastrou "marketing" e ainda não gastou), e recusá-la o
            // obrigaria a apagar e recriar a linha todo mês em que o gasto some.
            'monthly_amount' => [$required, 'numeric', 'min:0'],

            'is_active' => ['boolean'],
        ]);
    }
}

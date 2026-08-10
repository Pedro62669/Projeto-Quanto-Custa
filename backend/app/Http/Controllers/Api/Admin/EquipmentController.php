<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EquipmentResource;
use App\Models\Equipment;
use App\Services\Pricing\DepreciationCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CRUD do parque de máquinas (somente admin — ver middleware na rota).
 *
 * Nenhum método filtra por empresa, e isso é o desenho e não um esquecimento:
 * o TenantScope injeta `where tenant_id = ?` em toda query do Equipment, e o
 * `creating` da trait carimba a empresa na escrita. Um filtro manual aqui seria
 * redundante e — pior — sugeriria que o isolamento depende de o controller
 * lembrar dele.
 *
 * O route model binding do `show`/`update`/`destroy` também passa pelo escopo:
 * pedir o id de uma máquina de outra empresa devolve 404, não 403. É a resposta
 * certa, porque 403 confirmaria que o registro existe.
 */
class EquipmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $equipment = Equipment::query()
            ->when(
                $request->filled('search'),
                fn ($q) => $q->whereLike('name', "%{$request->string('search')}%", caseSensitive: false),
            )
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return EquipmentResource::collection($equipment);
    }

    public function store(Request $request): JsonResponse
    {
        $equipment = Equipment::create($this->validated($request));

        return (new EquipmentResource($equipment))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Equipment $equipment): EquipmentResource
    {
        return new EquipmentResource($equipment);
    }

    public function update(Request $request, Equipment $equipment): EquipmentResource
    {
        $equipment->update($this->validated($request, $equipment));

        return new EquipmentResource($equipment->fresh());
    }

    /**
     * Exclusão de verdade, diferente do catálogo de materiais.
     *
     * Material é desativado porque orçamentos emitidos apontam para ele por
     * chave estrangeira, e apagá-lo deixaria o histórico órfão. Máquina não é
     * referenciada por nenhum orçamento: ela entra no preço pelo total mensal
     * do parque, que o `pricing_snapshot` de cada orçamento já congelou. Vender
     * uma máquina e removê-la do inventário não reescreve o passado.
     */
    public function destroy(Equipment $equipment): JsonResponse
    {
        $equipment->delete();

        return response()->json([
            'message' => 'Equipamento removido do inventário. Os orçamentos já emitidos não mudam.',
        ]);
    }

    /**
     * Impacto da depreciação no custo unitário.
     *
     * Vive aqui, e não numa rota de relatório, porque é a pergunta que o
     * usuário faz JUNTO do cadastro: acabou de lançar a máquina e quer ver
     * quanto ela pesa em cada peça. A produção mensal vem por query string
     * porque é uma simulação — nada é gravado.
     */
    public function depreciationImpact(Request $request, DepreciationCalculator $calculator): JsonResponse
    {
        $validated = $request->validate([
            'monthly_production' => ['required', 'numeric', 'min:1'],
        ], [
            'monthly_production.required' => 'Informe a produção mensal estimada, em unidades.',
            'monthly_production.min' => 'A produção mensal precisa ser de ao menos uma unidade.',
        ]);

        return response()->json([
            'data' => $calculator->impact((float) $validated['monthly_production']),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Equipment $equipment = null): array
    {
        $required = $equipment ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'purchase_value' => [$required, 'numeric', 'min:0.01'],

            /*
             * min:1 é a regra que sustenta a divisão da depreciação mensal.
             * max:600 (50 anos) não é preciosismo: é o limite que separa um erro
             * de digitação de uma vida útil plausível — quem digita 6000 meses
             * quis dizer 60, e sem o teto o sistema aceitaria uma depreciação
             * de centavos por mês sem reclamar.
             */
            'useful_life_months' => [$required, 'integer', 'min:1', 'max:600'],
        ], [
            'useful_life_months.min' => 'A vida útil precisa ser de ao menos 1 mês.',
            'useful_life_months.max' => 'Vida útil acima de 600 meses (50 anos) provavelmente é erro de digitação.',
            'purchase_value.min' => 'Informe o valor de aquisição da máquina.',
        ]);
    }
}

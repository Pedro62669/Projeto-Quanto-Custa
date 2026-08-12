<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\GrainDirection;
use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Http\Controllers\Controller;
use App\Http\Resources\MaterialResource;
use App\Models\Material;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * CRUD de matérias-primas (somente admin — ver middleware na rota).
 *
 * A listagem pública para o formulário do usuário fica em
 * Api\MaterialController; aqui é a gestão do catálogo.
 */
class MaterialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $materials = Material::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('search'), fn ($q) => $q->whereLike('name', "%{$request->string('search')}%", caseSensitive: false))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return MaterialResource::collection($materials);
    }

    public function store(Request $request): JsonResponse
    {
        $material = Material::create($this->validated($request));

        return (new MaterialResource($material))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Material $material): MaterialResource
    {
        return new MaterialResource($material);
    }

    public function update(Request $request, Material $material): MaterialResource
    {
        $material->update($this->validated($request, $material));

        return new MaterialResource($material->fresh());
    }

    /**
     * Desativa em vez de apagar.
     *
     * A FK de `quotes` é RESTRICT: apagar um material usado em orçamentos
     * deixaria o histórico órfão e insustentável. Desativar tira do formulário
     * sem tocar no passado.
     */
    public function destroy(Material $material): JsonResponse
    {
        $material->update(['is_active' => false]);

        return response()->json([
            'message' => 'Matéria-prima desativada. O histórico de orçamentos foi preservado.',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Material $material = null): array
    {
        $required = $material ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::enum(MaterialType::class)],
            'description' => ['nullable', 'string', 'max:1000'],

            'cost_unit' => [$required, Rule::enum(MaterialUnit::class)],
            'cost_per_unit' => [$required, 'numeric', 'min:0.0001'],

            // Regra de negócio central do cadastro: sem gramatura, um material
            // cotado em kg não pode ser convertido para R$/m² e quebraria o
            // cálculo em runtime. Exigimos aqui, na borda.
            'grammage_kg_per_m2' => [
                Rule::requiredIf(fn () => $request->input('cost_unit') === MaterialUnit::Kilogram->value),
                'nullable', 'numeric', 'min:0.0001', 'max:100',
            ],

            /*
             * Lote de compra com frete rateado — o caminho mais fiel de custo.
             *
             * Todos opcionais e independentes do bloco acima: quem já cadastrou
             * R$/m² direto continua funcionando. Quando o conjunto está
             * completo, ele PASSA NA FRENTE do `cost_per_unit`, porque sai da
             * nota que acabou de chegar em vez de um número digitado numa compra
             * anterior. Ver Material::costPerSquareMeter().
             */
            'sheet_width_mm' => [
                'nullable', 'integer', 'min:1', 'max:5000',
                // As duas medidas andam juntas: uma só não fecha área nenhuma.
                'required_with:sheet_length_mm',
            ],
            'sheet_length_mm' => [
                'nullable', 'integer', 'min:1', 'max:5000',
                'required_with:sheet_width_mm',
            ],

            /*
             * Sentido da fibra.
             *
             * A coluna e o enum existem desde o plano de corte, e o nesting os
             * consulta para decidir se uma peça pode girar 90° — mas nenhuma
             * regra aqui os aceitava, então o valor ficava preso no padrão
             * ("sem fibra") e o arranjo girava peça de papelão que na bancada
             * empena depois de colada.
             */
            'grain_direction' => ['nullable', Rule::enum(GrainDirection::class)],

            // min:1 e não min:0 — lote de zero itens estouraria a divisão.
            'lot_quantity' => [
                'nullable', 'integer', 'min:1', 'max:1000000',
                'required_with:lot_purchase_cost',
            ],
            'lot_purchase_cost' => [
                'nullable', 'numeric', 'min:0',
                'required_with:lot_quantity',
            ],

            // Frete ausente é retirada no fornecedor, não cadastro incompleto.
            'lot_freight_cost' => ['nullable', 'numeric', 'min:0'],

            'default_waste_percent' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'thickness_mm' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'color_hex' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'texture_url' => ['nullable', 'url', 'max:2048'],
            'is_active' => ['boolean'],
        ], [
            'grammage_kg_per_m2.required' => 'Informe a gramatura (kg/m²) para materiais cotados em quilo.',
            'sheet_width_mm.required_with' => 'Informe as duas medidas da folha — uma só não fecha área.',
            'sheet_length_mm.required_with' => 'Informe as duas medidas da folha — uma só não fecha área.',
            'lot_quantity.required_with' => 'Informe quantos itens vieram no lote para ratear o valor da compra.',
            'lot_purchase_cost.required_with' => 'Informe o valor pago pelo lote.',
            'color_hex.regex' => 'A cor deve estar no formato hexadecimal, ex.: #C8A06A.',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Cadastro de fornecedores de insumos.
 */
class SupplierController extends Controller
{
    /**
     * Os campos do material que viajam junto do fornecedor.
     *
     * Três, e não o registro inteiro: a listagem só desenha etiquetas com o
     * nome. Trazer o material completo entregaria custo de compra e gramatura a
     * quem abriu a tela de fornecedores — e a usuário comum, que o
     * MaterialResource deliberadamente esconde.
     *
     * @var list<string>
     */
    private const CAMPOS_DO_MATERIAL = ['materials.id', 'materials.name', 'materials.type'];

    public function index(Request $request): JsonResponse
    {
        $suppliers = Supplier::query()
            // Eager loading: sem ele, uma página de 25 fornecedores faria 26
            // consultas para desenhar as etiquetas.
            ->with(['materials' => fn ($q) => $q->select(self::CAMPOS_DO_MATERIAL)])
            ->when($request->filled('search'), fn ($q) => $q->whereLike(
                'name', "%{$request->string('search')}%", caseSensitive: false,
            ))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return response()->json($suppliers);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $this->validated($request);

        // `material_ids` não é coluna: vai para a tabela de vínculo, logo
        // abaixo. O $fillable do modelo já o descartaria, mas contar com isso
        // esconderia a intenção de quem lê.
        $supplier = Supplier::create(Arr::except($dados, 'material_ids'));
        $this->sincronizaMateriais($supplier, $dados);

        return response()->json(['data' => $this->comMateriais($supplier)], JsonResponse::HTTP_CREATED);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return response()->json(['data' => $this->comMateriais($supplier)]);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $dados = $this->validated($request, $supplier);

        $supplier->update(Arr::except($dados, 'material_ids'));
        $this->sincronizaMateriais($supplier, $dados);

        return response()->json(['data' => $this->comMateriais($supplier->fresh())]);
    }

    /** Desativa: há compras lançadas apontando para ele. Ver ClientController. */
    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->update(['is_active' => false]);

        return response()->json([
            'message' => 'Fornecedor desativado. As compras lançadas foram preservadas.',
        ]);
    }

    /**
     * Grava o que o fornecedor vende.
     *
     * Só toca na relação se a chave veio no corpo. A diferença importa na
     * edição: um PUT que atualiza apenas o telefone não pode apagar a lista de
     * materiais só porque não a mencionou — `sync([])` com array ausente
     * desligaria tudo em silêncio.
     *
     * @param  array<string, mixed>  $dados
     */
    private function sincronizaMateriais(Supplier $supplier, array $dados): void
    {
        if (! array_key_exists('material_ids', $dados)) {
            return;
        }

        $supplier->materials()->sync($dados['material_ids']);
    }

    /** Recarrega a relação para a resposta devolver o que acabou de gravar. */
    private function comMateriais(Supplier $supplier): Supplier
    {
        return $supplier->load(['materials' => fn ($q) => $q->select(self::CAMPOS_DO_MATERIAL)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $required = $supplier ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'state' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],

            'material_ids' => ['sometimes', 'array'],

            /*
             * `Rule::in` sobre os ids da empresa, e NÃO `exists:materials,id`.
             *
             * A regra `exists` consulta a tabela crua, por fora do Eloquent —
             * logo, por fora do TenantScope. Ela aceitaria o id de um material
             * de outra assinante, e o vínculo gravado vazaria o nome desse
             * material na tela de quem o enviou. `Material::query()` já sai
             * filtrado pela empresa da sessão, então a lista abaixo é
             * exatamente o conjunto legítimo.
             */
            'material_ids.*' => ['integer', Rule::in(Material::query()->pluck('id')->all())],
        ]);
    }
}

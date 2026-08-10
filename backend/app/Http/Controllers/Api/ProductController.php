<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cadastro de produtos prontos para revenda.
 */
class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->when($request->filled('search'), fn ($q) => $q->whereLike(
                'name', "%{$request->string('search')}%", caseSensitive: false,
            ))
            ->when($request->boolean('in_stock'), fn ($q) => $q->inStock())
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        // A margem acompanha cada linha: é a pergunta que se faz olhando uma
        // lista de revenda, e calculá-la no cliente convidaria duas fórmulas.
        $products->getCollection()->transform(fn (Product $p): array => [
            ...$p->toArray(),
            'margin_percent' => $p->marginPercent(),
        ]);

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $product = Product::create($this->validated($request));

        return response()->json([
            'data' => [...$product->toArray(), 'margin_percent' => $product->marginPercent()],
        ], JsonResponse::HTTP_CREATED);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json([
            'data' => [...$product->toArray(), 'margin_percent' => $product->marginPercent()],
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $product->update($this->validated($request, $product));
        $product = $product->fresh();

        return response()->json([
            'data' => [...$product->toArray(), 'margin_percent' => $product->marginPercent()],
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->update(['is_active' => false]);

        return response()->json(['message' => 'Produto desativado.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Product $product = null): array
    {
        $required = $product ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],

            'sku' => [
                'nullable', 'string', 'max:60',
                Rule::unique('products', 'sku')
                    ->where('tenant_id', $request->user()->tenant_id)
                    ->ignore($product?->id),
            ],

            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],

            /*
             * Aceita negativo: estoque no vermelho é um dado verdadeiro (vendeu
             * o que não tinha), e recusá-lo faria o lançamento falhar em vez de
             * mostrar o problema a quem pode resolvê-lo.
             */
            'stock_quantity' => ['nullable', 'integer', 'min:-1000000', 'max:1000000'],

            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ], [
            'sku.unique' => 'Já existe um produto com este SKU.',
        ]);
    }
}

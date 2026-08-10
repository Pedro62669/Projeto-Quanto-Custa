<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cadastro de fornecedores de insumos.
 */
class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $suppliers = Supplier::query()
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
        $supplier = Supplier::create($this->validated($request));

        return response()->json(['data' => $supplier], JsonResponse::HTTP_CREATED);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return response()->json(['data' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return response()->json(['data' => $supplier->fresh()]);
    }

    /** Desativa: há compras lançadas apontando para ele. Ver ClientController. */
    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->update(['is_active' => false]);

        return response()->json([
            'message' => 'Fornecedor desativado. As compras lançadas foram preservadas.',
        ]);
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
        ]);
    }
}

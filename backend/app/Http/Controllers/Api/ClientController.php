<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cadastro de clientes.
 *
 * Rota de usuário e não de admin: quem atende o cliente é quem orça, e obrigar
 * o dono da empresa a cadastrar cada comprador transformaria o gargalo em
 * política. Isolado por empresa pelo TenantScope, como tudo mais.
 */
class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clients = Client::query()
            ->when($request->filled('search'), fn ($q) => $q->whereLike(
                'name', "%{$request->string('search')}%", caseSensitive: false,
            ))
            ->when($request->filled('state'), fn ($q) => $q->where('state', $request->string('state')))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return response()->json($clients);
    }

    public function store(Request $request): JsonResponse
    {
        $client = Client::create($this->validated($request));

        return response()->json(['data' => $client], JsonResponse::HTTP_CREATED);
    }

    public function show(Client $client): JsonResponse
    {
        return response()->json(['data' => $client]);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $client->update($this->validated($request, $client));

        return response()->json(['data' => $client->fresh()]);
    }

    /**
     * Desativa em vez de apagar.
     *
     * Cliente tem orçamento e transação apontando para ele. Apagar deixaria o
     * histórico financeiro sem contraparte — e o caixa precisa continuar
     * batendo com o extrato bancário, que não esquece quem pagou.
     */
    public function destroy(Client $client): JsonResponse
    {
        $client->update(['is_active' => false]);

        return response()->json([
            'message' => 'Cliente desativado. Orçamentos e lançamentos foram preservados.',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Client $client = null): array
    {
        $required = $client ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],

            /*
             * Único por EMPRESA, não global: o mesmo cliente pode comprar de
             * duas cartonagens, e um único global impediria a segunda de
             * cadastrá-lo. O ignore permite salvar o próprio registro sem
             * colidir consigo mesmo.
             */
            'cpf_cnpj' => [
                'nullable', 'string', 'max:14',
                Rule::unique('clients', 'cpf_cnpj')
                    ->where('tenant_id', $request->user()->tenant_id)
                    ->ignore($client?->id),
            ],

            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'state' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ], [
            'cpf_cnpj.unique' => 'Já existe um cliente com este CPF/CNPJ.',
            'state.size' => 'Informe a UF com duas letras, ex.: SP.',
        ]);
    }
}

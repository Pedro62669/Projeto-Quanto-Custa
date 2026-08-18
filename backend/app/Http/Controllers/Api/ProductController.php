<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ProductKind;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\Finance\FinancialEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Cadastro de produtos prontos para revenda.
 */
class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->with('quote:id,reference')
            ->when($request->filled('search'), fn ($q) => $q->whereLike(
                'name', "%{$request->string('search')}%", caseSensitive: false,
            ))

            // Caixa pronta e mercadoria avulsa vivem na mesma tabela e respondem
            // a perguntas diferentes: uma é "o que já produzimos", a outra "o
            // que revendemos". A tela as separa em abas.
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))

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
        /*
         * O formulário cria MERCADORIA, sempre.
         *
         * Caixa pronta nasce de um orçamento aprovado — é dele que vêm o preço
         * que o cliente aceitou e o custo que o motor calculou. Deixar o
         * formulário declarar `kind: box` produziria uma caixa de catálogo sem
         * proposta por trás: o link para a origem apontaria para lugar nenhum e
         * o preço seria um número digitado se passando por um número calculado.
         */
        $product = Product::create([
            ...$this->validated($request),
            'kind' => ProductKind::Merchandise,
            'quote_id' => null,
        ]);

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

    /**
     * POST /api/products/{product}/sell — a venda que liga o catálogo ao caixa.
     *
     * Era o elo que faltava. `TransactionCategory::ProductSale` existia no enum
     * desde a Fase 4 e nunca fora usado: dava para cadastrar produto, preço e
     * estoque, e vender era uma operação que o sistema não tinha — quem vendia
     * lançava a entrada à mão e baixava o estoque editando o cadastro, duas
     * ações separadas que ninguém garantia que aconteciam juntas.
     *
     * Aqui elas são uma transação de banco só.
     */
    public function sell(Request $request, Product $product, FinancialEngine $financial): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],

            /*
             * Preço opcional: o do cadastro é o padrão, e informar outro cobre o
             * desconto de balcão sem obrigar a editar o produto — editar mudaria
             * o preço de todas as vendas seguintes por causa de uma.
             */
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],

            // Sem `exists`: a regra consulta a tabela por fora do TenantScope e
            // aceitaria o cliente da empresa vizinha. Ver TransactionController.
            'client_id' => ['nullable', 'integer'],

            'installments' => ['nullable', 'integer', 'min:1', 'max:60'],
            'first_due_date' => ['nullable', 'date'],
        ]);

        $quantidade = (int) $validated['quantity'];

        /*
         * Estoque insuficiente RECUSA, mesmo a coluna aceitando negativo.
         *
         * O negativo existe para o dado verdadeiro que já aconteceu — uma
         * contagem que revelou falta. Deixar a VENDA cavar o buraco é outra
         * coisa: seria o sistema confirmando uma entrega que a prateleira não
         * tem, e o erro só apareceria na hora de despachar.
         */
        if ($quantidade > $product->stock_quantity) {
            return response()->json([
                'message' => 'Estoque insuficiente.',
                'errors' => ['quantity' => [
                    "Há {$product->stock_quantity} em estoque e a venda pede {$quantidade}. "
                    .'Ajuste o estoque no cadastro se a contagem estiver desatualizada.',
                ]],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $client = isset($validated['client_id'])
            ? Client::query()->findOrFail($validated['client_id'])
            : null;

        $precoUnitario = (float) ($validated['unit_price'] ?? $product->sale_price);

        $transaction = DB::transaction(function () use (
            $product, $client, $validated, $quantidade, $precoUnitario, $financial,
        ): Transaction {
            $transaction = Transaction::create([
                'client_id' => $client?->id,
                'product_id' => $product->id,
                'type' => TransactionType::Entry,
                'category' => TransactionCategory::ProductSale,
                'amount' => $precoUnitario * $quantidade,
                'description' => "Venda de {$quantidade}× {$product->name}",
                'transaction_date' => now(),
            ]);

            // `decrement` e não `update` com o valor lido: duas vendas
            // simultâneas do mesmo produto leriam o mesmo estoque e gravariam a
            // mesma baixa, sumindo com uma delas.
            $product->decrement('stock_quantity', $quantidade);

            // As parcelas saem pelo MESMO motor da aprovação de orçamento: uma
            // venda a prazo do catálogo aparece no fluxo de caixa igual a uma
            // venda de embalagem, porque para o dinheiro elas são a mesma coisa.
            $financial->generateInstallments(
                $transaction,
                (int) ($validated['installments'] ?? 1),
                isset($validated['first_due_date'])
                    ? Carbon::parse($validated['first_due_date'])
                    : null,
            );

            return $transaction;
        });

        return response()->json([
            'message' => 'Venda lançada no caixa e estoque baixado.',
            'data' => [
                'product' => [
                    ...$product->fresh()->toArray(),
                    'margin_percent' => $product->fresh()->marginPercent(),
                ],
                'transaction' => $transaction->load('installments'),
            ],
        ], JsonResponse::HTTP_CREATED);
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

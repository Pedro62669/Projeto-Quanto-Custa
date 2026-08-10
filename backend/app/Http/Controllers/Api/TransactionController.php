<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Services\Finance\FinancialEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Lançamentos manuais no livro caixa.
 *
 * A venda de orçamento NÃO entra por aqui: ela nasce da aprovação da proposta,
 * em QuoteApprovalController. Permitir o lançamento manual de uma `quote_sale`
 * abriria caminho para duas transações do mesmo orçamento — e a margem de
 * contribuição contaria a mesma venda duas vezes.
 */
class TransactionController extends Controller
{
    public function __construct(
        private readonly FinancialEngine $financial,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $transactions = Transaction::query()
            ->with(['client:id,name', 'supplier:id,name', 'installments'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('month') && $request->filled('year'), fn ($q) => $q
                ->whereYear('transaction_date', $request->integer('year'))
                ->whereMonth('transaction_date', $request->integer('month')))
            ->latest('transaction_date')
            ->paginate($request->integer('per_page', 25));

        return response()->json($transactions);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(TransactionType::class)],

            'category' => [
                'required',
                Rule::enum(TransactionCategory::class)
                    // Bloqueia o lançamento manual de venda de orçamento: ela
                    // tem caminho próprio, e duplicá-la contaminaria a margem.
                    ->except([TransactionCategory::QuoteSale]),
            ],

            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'description' => ['required', 'string', 'max:255'],
            'transaction_date' => ['required', 'date'],

            /*
             * Sem `exists`: a regra consulta a tabela sem o TenantScope e
             * aprovaria a contraparte da empresa vizinha, gravando um
             * lançamento cruzado. Quem confere são os findOrFail escopados
             * abaixo — mesma correção do QuoteApprovalController.
             */
            'client_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],

            'installments' => ['nullable', 'integer', 'min:1', 'max:60'],
            'first_due_date' => ['nullable', 'date'],
        ], [
            'category.enum' => 'Venda de orçamento é lançada ao aprovar a proposta, não manualmente.',
        ]);

        // Resolvidos pelo model escopado: um id de outra empresa vira 404 aqui,
        // em vez de virar um lançamento no caixa errado.
        $client = isset($validated['client_id'])
            ? Client::query()->findOrFail($validated['client_id'])
            : null;

        $supplier = isset($validated['supplier_id'])
            ? Supplier::query()->findOrFail($validated['supplier_id'])
            : null;

        $transaction = DB::transaction(function () use ($validated, $client, $supplier) {
            $transaction = Transaction::create([
                'type' => $validated['type'],
                'category' => $validated['category'],
                'amount' => $validated['amount'],
                'description' => $validated['description'],
                'transaction_date' => $validated['transaction_date'],
                'client_id' => $client?->id,
                'supplier_id' => $supplier?->id,
            ]);

            // Sem parcelas informadas, uma só: à vista é o caso comum, e
            // obrigar o campo transformaria todo lançamento rápido em formulário.
            $this->financial->generateInstallments(
                $transaction,
                (int) ($validated['installments'] ?? 1),
                isset($validated['first_due_date'])
                    ? Carbon::parse($validated['first_due_date'])
                    : null,
            );

            return $transaction;
        });

        return response()->json(
            ['data' => $transaction->load('installments')],
            JsonResponse::HTTP_CREATED,
        );
    }

    public function show(Transaction $transaction): JsonResponse
    {
        return response()->json([
            'data' => $transaction->load(['client', 'supplier', 'quote:id,reference', 'installments']),
        ]);
    }

    /**
     * Apaga o lançamento e as parcelas junto (cascade).
     *
     * Exclusão de verdade, e não lógica: um lançamento errado no caixa não é
     * histórico — é ruído que desalinha a conciliação com o extrato. O que
     * precisa sobreviver é o registro de ACESSO da exclusão, e disso cuida a
     * auditoria do Marco Civil.
     */
    public function destroy(Transaction $transaction): JsonResponse
    {
        $transaction->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}

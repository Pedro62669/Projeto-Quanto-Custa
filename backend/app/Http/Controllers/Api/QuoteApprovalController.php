<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\QuoteStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Quote;
use App\Models\Transaction;
use App\Services\Finance\FinancialEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aprovação de orçamento — a ponte entre a proposta e o caixa.
 *
 * Endpoint próprio, e não um PATCH em `quotes.status`, porque aprovar não é
 * mudar um rótulo: é lançar dinheiro. A transição carrega parâmetros que só
 * existem neste momento (em quantas vezes o cliente vai pagar, a partir de
 * quando) e produz efeito colateral em duas outras tabelas. Um PATCH genérico
 * de campo esconderia isso atrás de uma edição banal.
 */
class QuoteApprovalController extends Controller
{
    public function __construct(
        private readonly FinancialEngine $financial,
    ) {}

    public function __invoke(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('update', $quote);

        $validated = $request->validate([
            'installments' => ['nullable', 'integer', 'min:1', 'max:60'],
            'first_due_date' => ['nullable', 'date'],

            /*
             * Vincular a um cliente cadastrado é opcional: muita venda de
             * cartonagem fecha com um nome e um WhatsApp, e exigir cadastro
             * completo para faturar travaria o caminho mais comum.
             *
             * Sem `exists` aqui de propósito — quem confere a existência É o
             * findOrFail escopado abaixo. A regra de validação consultaria a
             * tabela sem o TenantScope e aprovaria o cliente do vizinho.
             */
            'client_id' => ['nullable', 'integer'],
        ]);

        /*
         * Idempotência explícita.
         *
         * Aprovar duas vezes lançaria a mesma venda duas vezes no caixa e
         * dobraria o faturamento do mês. 422 com mensagem clara é melhor que
         * uma guarda silenciosa — quem clicou duas vezes precisa saber que a
         * primeira funcionou.
         */
        if ($quote->status === QuoteStatus::Approved) {
            return response()->json([
                'message' => 'Este orçamento já foi aprovado e lançado no caixa.',
                'errors' => ['status' => ['Orçamento já aprovado.']],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        /*
         * O cliente é resolvido pelo MODEL ESCOPADO, e não aceito como id cru.
         *
         * `Rule::exists` consulta a tabela sem passar pelo TenantScope — ele é
         * um global scope do Eloquent, e a regra de validação usa o query
         * builder puro. Um `client_id` da empresa vizinha passaria na validação
         * e seria gravado no orçamento E na transação: escrita cruzada, a
         * metade silenciosa do IDOR. O findOrFail escopado devolve 404.
         */
        $client = isset($validated['client_id'])
            ? Client::query()->findOrFail($validated['client_id'])
            : null;

        $transaction = DB::transaction(function () use ($quote, $client, $validated): Transaction {
            $clientId = $client?->id ?? $quote->client_id;

            $quote->update([
                'status' => QuoteStatus::Approved,
                'client_id' => $clientId,
            ]);

            $transaction = Transaction::create([
                'client_id' => $clientId,
                'quote_id' => $quote->id,
                'type' => TransactionType::Entry,
                'category' => TransactionCategory::QuoteSale,
                'amount' => $quote->total_price,
                'description' => "Venda do orçamento {$quote->reference} — {$quote->client_name}",
                'transaction_date' => now(),
            ]);

            $this->financial->generateInstallments(
                $transaction,
                (int) ($validated['installments'] ?? 1),
                isset($validated['first_due_date'])
                    ? Carbon::parse($validated['first_due_date'])
                    : null,
            );

            return $transaction;
        });

        return response()->json([
            'message' => 'Orçamento aprovado e lançado no caixa.',
            'data' => [
                'quote' => [
                    'id' => $quote->id,
                    'reference' => $quote->reference,
                    'status' => $quote->fresh()->status->value,
                ],
                'transaction' => $transaction->load('installments'),
            ],
        ]);
    }

    /**
     * Cria o cliente a partir dos dados que o orçamento já guardou.
     *
     * Atalho para o caminho mais comum: o vendedor digitou nome e e-mail na
     * proposta e só na hora de fechar percebe que quer o cliente no cadastro.
     * Reaproveitar o que já foi digitado evita redigitar — e evita a segunda
     * grafia do mesmo nome, que é como um cadastro vira duas fichas.
     */
    public function promoteClient(Quote $quote): JsonResponse
    {
        $this->authorize('update', $quote);

        if ($quote->client_id !== null) {
            return response()->json(['data' => $quote->client]);
        }

        $client = Client::create([
            'name' => $quote->client_name,
            'email' => $quote->client_email,
            'cpf_cnpj' => $quote->client_document,
        ]);

        $quote->update(['client_id' => $client->id]);

        return response()->json(['data' => $client], JsonResponse::HTTP_CREATED);
    }
}

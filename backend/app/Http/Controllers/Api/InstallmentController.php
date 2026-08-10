<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Installment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Quitação de parcelas.
 *
 * Endpoint próprio e não um PATCH genérico de campos: quitar é a única
 * alteração legítima numa parcela. Valor e vencimento saem do parcelamento e
 * mudá-los depois desalinharia a soma das parcelas do total da transação — a
 * invariante que sustenta os dois números do painel.
 */
class InstallmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $installments = Installment::query()
            ->with('transaction:id,type,category,description,client_id,supplier_id')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('month') && $request->filled('year'), fn ($q) => $q
                ->dueIn($request->integer('month'), $request->integer('year')))
            ->when($request->boolean('overdue'), fn ($q) => $q
                ->pending()
                ->whereDate('due_date', '<', now()->startOfDay()))
            ->orderBy('due_date')
            ->paginate($request->integer('per_page', 50));

        return response()->json($installments);
    }

    /** POST /api/installments/{installment}/settle */
    public function settle(Request $request, Installment $installment): JsonResponse
    {
        $validated = $request->validate([
            /*
             * Data opcional, com HOJE como padrão. Aceitar a data serve ao
             * lançamento retroativo (o cliente pagou na sexta, o dono lança na
             * segunda) — e é exatamente essa diferença entre pagar e registrar
             * que o caixa realizado precisa refletir com honestidade.
             */
            'payment_date' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'payment_date.before_or_equal' => 'Não é possível registrar um pagamento no futuro.',
        ]);

        $installment->settle(
            isset($validated['payment_date']) ? Carbon::parse($validated['payment_date']) : null,
        );

        return response()->json(['data' => $installment->fresh()]);
    }

    /** DELETE /api/installments/{installment}/settle — desfaz a quitação. */
    public function unsettle(Installment $installment): JsonResponse
    {
        $installment->unsettle();

        return response()->json(['data' => $installment->fresh()]);
    }
}

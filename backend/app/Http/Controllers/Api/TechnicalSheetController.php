<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Services\Production\CutTemplateCalculator;
use Illuminate\Http\JsonResponse;

/**
 * Ficha técnica de produção — o documento do chão de fábrica.
 *
 * O gabarito é calculado NO SERVIDOR e entregue pronto, e isso é deliberado:
 * duplicá-lo no Next.js criaria uma terceira implementação da geometria (PHP
 * do preço, TS do preview, TS da ficha) — e a paridade só vigia as duas
 * primeiras. A terceira divergiria em silêncio, e o silêncio apareceria como
 * papelão cortado errado.
 */
class TechnicalSheetController extends Controller
{
    public function __invoke(Quote $quote, CutTemplateCalculator $cutTemplate): JsonResponse
    {
        // Mesma política do orçamento: quem não pode ver a proposta não pode
        // ver a ficha, que carrega as mesmas medidas e o mesmo cliente.
        $this->authorize('view', $quote);

        $snapshot = $quote->pricing_snapshot ?? [];

        /*
         * A espessura sai do SNAPSHOT, não do material atual.
         *
         * Se o cadastro do papelão mudar de 1,9 para 3mm amanhã, a ficha de um
         * orçamento aprovado ontem tem que continuar mandando cortar a peça que
         * foi vendida. O snapshot é a fotografia do que foi combinado — usá-lo
         * aqui é o que mantém a produção fiel ao preço.
         */
        $thickness = (float) ($snapshot['material']['thickness_mm'] ?? 0.0);

        $gabarito = $cutTemplate->forQuote(
            model: $quote->box_model,
            widthMm: (float) $quote->width_mm,
            heightMm: (float) $quote->height_mm,
            depthMm: (float) $quote->depth_mm,
            thicknessMm: $thickness,
            lidMm: $quote->lid_width_mm !== null ? [
                'width' => (float) $quote->lid_width_mm,
                'depth' => (float) $quote->lid_depth_mm,
                'height' => (float) $quote->lid_height_mm,
            ] : null,
        );

        return response()->json([
            'data' => [
                'quote' => [
                    'id' => $quote->id,
                    'reference' => $quote->reference,
                    'client_name' => $quote->client_name,
                    'status' => $quote->status->value,
                    'created_at' => $quote->created_at?->toIso8601String(),
                    'notes' => $quote->notes,
                ],

                'specification' => [
                    'box_model' => $quote->box_model->value,
                    'box_model_label' => $quote->box_model->label(),
                    'width_mm' => (float) $quote->width_mm,
                    'height_mm' => (float) $quote->height_mm,
                    'depth_mm' => (float) $quote->depth_mm,
                    'thickness_mm' => $thickness,
                    'quantity' => $quote->quantity,
                    'material' => $snapshot['material']['name'] ?? $quote->material?->name,
                ],

                'cut_template' => $gabarito,

                /*
                 * Lista de separação: o que sair do estoque para o LOTE, não
                 * para uma peça. Quem vai ao estoque leva o pedido inteiro, e
                 * multiplicar mentalmente por 250 na frente da prateleira é
                 * como se erra a retirada.
                 */
                'picking_list' => $this->pickingList($quote, $gabarito),
            ],
        ]);
    }

    /**
     * Consolida as peças pelo tamanho do lote.
     *
     * @param  array<string, mixed>  $gabarito
     * @return list<array<string, mixed>>
     */
    private function pickingList(Quote $quote, array $gabarito): array
    {
        $linhas = [];

        foreach (['structure' => 'Papelão cinza', 'wrap' => 'Revestimento'] as $papel => $rotulo) {
            foreach ($gabarito[$papel] as $peca) {
                $linhas[] = [
                    'material_role' => $papel,
                    'material_label' => $rotulo,
                    'piece' => $peca['name'],
                    'size' => sprintf('%.1f × %.1f mm', $peca['width_mm'], $peca['height_mm']),
                    'per_unit' => $peca['quantity'],
                    'total' => $peca['quantity'] * $quote->quantity,
                ];
            }
        }

        return $linhas;
    }
}

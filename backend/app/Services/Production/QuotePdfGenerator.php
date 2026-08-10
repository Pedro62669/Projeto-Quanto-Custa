<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Models\Quote;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Facades\Storage;

/**
 * Orçamento comercial em PDF — o documento que representa a marca do assinante.
 *
 * Diferente da ficha técnica, este documento sai do sistema e chega ao cliente
 * final. Duas consequências no desenho:
 *
 *  1. Nada de custo aparece. Preço unitário e total, sim; material, mão de obra
 *     e margem, nunca. Um PDF que vaza a estrutura de custo entrega ao cliente
 *     o argumento da próxima negociação.
 *  2. O logotipo vira base64. O dompdf resolve caminhos relativos contra o
 *     próprio processo, e um src que funciona no navegador silenciosamente não
 *     renderiza no PDF — o cliente recebe a proposta com um quadrado vazio no
 *     lugar da marca, e ninguém percebe até ele responder.
 */
class QuotePdfGenerator
{
    /**
     * Tamanho máximo do logotipo embutido, em bytes.
     *
     * Público porque o CompanyController valida o upload contra ele. Dois
     * limites separados divergiriam no primeiro ajuste, e a divergência teria a
     * pior forma: o upload aceitaria uma imagem que este gerador depois recusa
     * em silêncio, e a proposta sairia sem marca sem ninguém entender por quê.
     */
    public const MAX_LOGO_BYTES = 2_097_152;

    public function generate(Quote $quote, Tenant $tenant): PdfDocument
    {
        $quote->loadMissing(['material', 'client', 'transactions.installments']);

        return Pdf::loadView('pdf.quote', [
            'quote' => $quote,
            'tenant' => $tenant,
            'logo' => $this->logoAsDataUri($tenant),
            'payment' => $this->paymentTerms($quote),
            'snapshot' => $quote->pricing_snapshot ?? [],
        ])->setPaper('a4');
    }

    /** Nome do arquivo, previsível e ordenável. */
    public function filename(Quote $quote): string
    {
        return "orcamento-{$quote->reference}.pdf";
    }

    /**
     * Logotipo como data URI.
     *
     * Devolve null em silêncio quando não há logo, quando o arquivo sumiu do
     * disco ou quando é grande demais. Um PDF sem marca é aceitável; uma
     * exceção no meio da emissão do orçamento não é — o usuário perderia a
     * proposta por causa de uma imagem.
     */
    private function logoAsDataUri(Tenant $tenant): ?string
    {
        $caminho = $tenant->logo_path;

        if ($caminho === null || $caminho === '') {
            return null;
        }

        $disco = Storage::disk((string) config('filesystems.default'));

        if (! $disco->exists($caminho)) {
            return null;
        }

        /*
         * Teto de tamanho: o dompdf carrega a imagem inteira em memória e a
         * embute no arquivo. Um logotipo de 8MB produziria um PDF que o
         * WhatsApp recusa — justamente o canal por onde a proposta é enviada.
         */
        if ($disco->size($caminho) > self::MAX_LOGO_BYTES) {
            return null;
        }

        $conteudo = $disco->get($caminho);
        $mime = $disco->mimeType($caminho) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($conteudo);
    }

    /**
     * Condições de pagamento, lidas do lançamento financeiro real.
     *
     * Sai da transação gerada na aprovação (Fase 4), e não de um texto digitado
     * à parte: o PDF promete ao cliente exatamente o que o caixa vai cobrar.
     * Dois lugares guardando a mesma condição divergiriam na primeira
     * renegociação.
     *
     * @return array<string, mixed>|null
     */
    private function paymentTerms(Quote $quote): ?array
    {
        $transacao = $quote->transactions->first();

        if ($transacao === null || $transacao->installments->isEmpty()) {
            return null;
        }

        $parcelas = $transacao->installments;
        $primeira = $parcelas->first();

        return [
            'count' => $parcelas->count(),
            'amount' => $primeira->amount,
            // A última pode diferir em centavos: a sobra da divisão vai nela.
            'last_amount' => $parcelas->last()->amount,
            'first_due_date' => $primeira->due_date,
            'total' => round((float) $parcelas->sum('amount'), 2),
        ];
    }
}

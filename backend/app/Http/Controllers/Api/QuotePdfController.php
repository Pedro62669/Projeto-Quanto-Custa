<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Services\Production\QuotePdfGenerator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download do orçamento em PDF.
 *
 * Rota autenticada e autorizada pela QuotePolicy: o PDF carrega dados do
 * cliente e o preço fechado, e um link público seria a forma mais fácil de
 * vazar a carteira inteira de um assinante — bastaria iterar os ids.
 */
class QuotePdfController extends Controller
{
    public function __invoke(Request $request, Quote $quote, QuotePdfGenerator $generator): Response
    {
        $this->authorize('view', $quote);

        $tenant = $request->user()->tenant;

        if ($tenant === null) {
            /*
             * Admin de plataforma não emite proposta: o PDF é assinado pela
             * marca de uma empresa, e ele não pertence a nenhuma. Emitir com o
             * cabeçalho vazio produziria um documento sem remetente.
             */
            abort(Response::HTTP_UNPROCESSABLE_ENTITY,
                'O orçamento é emitido em nome de uma empresa. Acesse com um usuário vinculado a um inquilino.');
        }

        return $generator->generate($quote, $tenant)
            ->download($generator->filename($quote));
    }
}

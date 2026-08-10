<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;

/**
 * Descadastro dos e-mails de engajamento — LGPD art. 18, §2º.
 *
 * Rota web e não de API, protegida por URL assinada em vez de login: exigir
 * autenticação para parar de receber e-mail transformaria "um clique" em
 * "lembre sua senha, entre no sistema, ache a configuração" — e é justamente
 * quem não quer mais entrar no sistema que está tentando sair da lista.
 *
 * A assinatura da URL é o que impede alguém de descadastrar terceiros iterando
 * ids. Sem ela, o link seria um endpoint anônimo de sabotagem.
 */
class DescadastroController extends Controller
{
    public function __invoke(User $user): View
    {
        /*
         * Idempotente: clicar duas vezes no link antigo não é erro. A data do
         * PRIMEIRO clique é preservada porque é ela que prova quando o titular
         * se opôs.
         */
        if ($user->aceitaEngajamento()) {
            $user->forceFill(['marketing_opt_out_at' => now()])->save();
        }

        return view('descadastro', [
            'email' => $user->email,
            'desde' => $user->marketing_opt_out_at,
        ]);
    }
}

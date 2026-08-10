<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Confirmação do e-mail do cadastro.
 *
 * Rota WEB e não de API, pelo mesmo motivo do descadastro: o destino é um clique
 * dentro de um e-mail, aberto no navegador. Precisa devolver uma página, e uma
 * rota de API devolveria JSON cru na tela de quem clicou.
 *
 * Sem login, protegida por URL assinada e temporária. Exigir sessão aqui seria
 * absurdo — a pessoa pode abrir o e-mail no celular enquanto se cadastrou no
 * computador, e não é razoável pedir que ela faça login de novo só para
 * confirmar que o endereço é dela.
 *
 * A resolução do usuário é MANUAL (sem route model binding) porque a assinatura
 * do link já cobre o id: o hash confere o e-mail, e o `signed` cobre o resto.
 */
class VerificacaoDeEmailController extends Controller
{
    public function __invoke(Request $request, int $id, string $hash): View
    {
        $usuario = User::find($id);

        /*
         * Compara o hash do e-mail ATUAL com o do link.
         *
         * É o que impede um link antigo de validar um endereço novo: se a pessoa
         * trocou o e-mail depois de pedir a verificação, o link velho confirmaria
         * um endereço que ninguém provou possuir — exatamente o que a
         * verificação existe para evitar.
         *
         * hash_equals para não vazar por tempo de comparação, como no AccessLog
         * e no webhook.
         */
        $valido = $usuario !== null
            && hash_equals($hash, sha1($usuario->getEmailForVerification()));

        if (! $valido) {
            return view('verificacao-de-email', [
                'sucesso' => false,
                'email' => null,
                'jaVerificado' => false,
            ]);
        }

        // Idempotente: reenviamos o e-mail, e clicar no link antigo depois de já
        // ter confirmado não pode virar erro na cara do usuário.
        $jaVerificado = $usuario->hasVerifiedEmail();

        if (! $jaVerificado) {
            $usuario->markEmailAsVerified();
            event(new Verified($usuario));
        }

        return view('verificacao-de-email', [
            'sucesso' => true,
            'email' => $usuario->email,
            'jaVerificado' => $jaVerificado,
        ]);
    }
}

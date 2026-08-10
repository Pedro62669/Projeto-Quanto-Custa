<?php

use App\Http\Controllers\DescadastroController;
use App\Http\Controllers\VerificacaoDeEmailController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Descadastro dos e-mails de engajamento — LGPD art. 18, §2º.
 *
 * Rota web e não de API porque o destino é um clique dentro de um e-mail, aberto
 * no navegador: precisa devolver uma página, não JSON.
 *
 * `signed` no lugar de login: exigir autenticação para sair de uma lista de
 * e-mails transformaria "um clique" em "lembre a senha, entre no sistema, ache a
 * configuração" — e quem quer sair da lista é justamente quem não quer entrar no
 * sistema. A assinatura da URL é o que impede alguém de descadastrar terceiros
 * iterando ids.
 */
Route::get('/descadastro/{user}', DescadastroController::class)
    ->name('engajamento.descadastro')
    ->middleware('signed');

/*
 * Confirmação de e-mail do cadastro.
 *
 * O NOME da rota importa: `verification.verify` é o que a notificação padrão do
 * Laravel procura para montar a URL assinada. Renomeá-la faria o envio estourar
 * na geração do link, e o cadastro inteiro falharia depois de já ter criado a
 * empresa.
 *
 * `signed` sozinho não basta aqui — a assinatura padrão desta notificação é
 * TEMPORÁRIA (60 min por padrão, `auth.verification.expire`), e o middleware
 * valida os dois. Diferente do descadastro, que não expira de propósito.
 */
Route::get('/email/verificar/{id}/{hash}', VerificacaoDeEmailController::class)
    ->name('verification.verify')
    ->middleware('signed');

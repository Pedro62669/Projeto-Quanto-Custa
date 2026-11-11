<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway de pagamento
    |--------------------------------------------------------------------------
    | 'fake' é o driver de desenvolvimento e teste: implementa a verificação de
    | assinatura de verdade, só não fala com rede nenhuma. Ver FakeGateway.
    |
    | Ao plugar Stripe ou Pagar.me, escreva a classe irmã, registre-a no
    | BillingServiceProvider e mude apenas esta chave.
    */

    'driver' => env('BILLING_DRIVER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Segredo de webhook
    |--------------------------------------------------------------------------
    | Chave do HMAC que autentica o corpo recebido do gateway. Sem ela o
    | endpoint de webhook viraria um botão público de upgrade de plano.
    |
    | O fallback para APP_KEY existe só para o ambiente local e de teste subirem
    | sem configuração extra — em produção, use a chave que o provedor emite.
    */

    'webhook_secret' => env('BILLING_WEBHOOK_SECRET', env('APP_KEY', 'segredo-local')),

    /*
    |--------------------------------------------------------------------------
    | Cabeçalho da assinatura
    |--------------------------------------------------------------------------
    | Cada provedor usa o seu: Stripe manda em `Stripe-Signature`, Pagar.me em
    | `X-Hub-Signature`.
    */

    'signature_header' => env('BILLING_SIGNATURE_HEADER', 'X-Webhook-Signature'),

    /*
    |--------------------------------------------------------------------------
    | Prazo de arrependimento (CDC, art. 49)
    |--------------------------------------------------------------------------
    | Sete dias CORRIDOS a contar da contratação. Configurável para cima, nunca
    | para baixo: sete é piso legal para contratação fora do estabelecimento, e
    | toda venda deste SaaS é pela internet.
    */

    'dias_de_arrependimento' => 7,

];

<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
| Numa arquitetura headless o frontend vive em outra origem, então o CORS
| deixa de ser detalhe e passa a ser parte do contrato da API.
|
| O default do Laravel é `allowed_origins => ['*']`, que é conveniente em
| desenvolvimento e inaceitável em produção: libera qualquer site a chamar a
| API com o token da vítima. Aqui a lista vem do .env — configure
| FRONTEND_URL com a origem real do Next.js em cada ambiente.
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    /*
     * Origens permitidas, separadas por vírgula em FRONTEND_URL.
     * Ex.: FRONTEND_URL="https://app.quantocusta.com.br,https://staging.quantocusta.com.br"
     */
    'allowed_origins' => array_filter(
        array_map('trim', explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000')))
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    /*
     * Nenhum header customizado precisa ser lido pelo JavaScript do frontend:
     * tudo o que ele consome vem no corpo da resposta.
     */
    'exposed_headers' => [],

    'max_age' => 3600,

    /*
     * false porque a autenticação é por token Bearer, não por cookie. Ligar
     * isto sem necessidade permitiria o envio de credenciais entre origens.
     */
    'supports_credentials' => false,

];

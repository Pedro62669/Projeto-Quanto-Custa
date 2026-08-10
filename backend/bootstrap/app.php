<?php

use App\Http\Middleware\CheckTenantLimits;
use App\Http\Middleware\RegistraAcesso;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Auditoria de acesso no grupo `api` inteiro, e não rota a rota.
         *
         * A obrigação do Marco Civil não admite lacuna: uma rota nova que
         * alguém acrescente amanhã precisa nascer auditada, sem depender de
         * lembrar de anotá-la. O filtro do que vira registro fica DENTRO do
         * middleware, onde está escrito o critério e o porquê.
         */
        $middleware->api(append: [
            RegistraAcesso::class,
        ]);

        /*
         * Alias para a trava de cota. É o único middleware do projeto que
         * recebe parâmetro (`quota:materials`), e por isso precisa de nome curto
         * na definição da rota — referenciar a classe com argumento na própria
         * linha da rota deixaria o arquivo de rotas ilegível.
         *
         * Os middlewares SEM parâmetro continuam sendo referenciados pela
         * classe, como EnsureUserIsAdmin: alias é ganho de legibilidade, não
         * indireção por indireção.
         */
        $middleware->alias([
            'quota' => CheckTenantLimits::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * DomainException é a exceção que o motor de precificação lança quando
         * a ENTRADA é inválida do ponto de vista do negócio: margem + imposto
         * acima de 100%, material em kg sem gramatura, nenhuma configuração de
         * custos cadastrada.
         *
         * Sem este mapeamento tudo isso viraria HTTP 500 ("erro do servidor"),
         * escondendo do usuário uma mensagem que ele consegue agir sobre. 422
         * comunica corretamente: a requisição foi entendida, mas os dados não
         * produzem um resultado válido.
         */
        $exceptions->render(function (DomainException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['pricing' => [$e->getMessage()]],
            ], 422);
        });
    })->create();

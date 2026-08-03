<?php

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
        //
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

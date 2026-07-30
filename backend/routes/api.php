<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\CostSettingController;
use App\Http\Controllers\Api\Admin\MaterialController as AdminMaterialController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API — arquitetura headless
|--------------------------------------------------------------------------
| Autenticação via Laravel Sanctum. Como o frontend Next.js roda em outro
| domínio, use tokens Bearer (não cookies de sessão), evitando a configuração
| de SPA stateful e o CSRF entre origens distintas.
|
| O middleware de admin é referenciado pela classe, não por alias — assim uma
| rota nova dentro do grupo já nasce protegida, sem depender de registro em
| bootstrap/app.php.
*/

/*
|--------------------------------------------------------------------------
| Público
|--------------------------------------------------------------------------
*/

// Throttle por IP, somado ao throttle por e-mail dentro do próprio controller.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

/*
|--------------------------------------------------------------------------
| Autenticado
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/me', fn (Request $r) => $r->user());

    /*
    |----------------------------------------------------------------------
    | Usuário — variáveis de cálculo, simulação e histórico
    |----------------------------------------------------------------------
    */

    // Alimenta o <select> de materiais e o 3D (cor/textura/espessura).
    Route::get('/materials', [MaterialController::class, 'index']);

    // Defaults do formulário (margem sugerida, moeda, modelos de caixa).
    Route::get('/pricing/parameters', [MaterialController::class, 'parameters']);

    // Cálculo em tempo real. Throttle generoso: é chamada em debounce a cada
    // alteração de input, mas ainda assim limitada para não virar vetor de abuso.
    Route::post('/quotes/simulate', [QuoteController::class, 'simulate'])
        ->middleware('throttle:120,1');

    Route::apiResource('quotes', QuoteController::class);

    /*
    |----------------------------------------------------------------------
    | Admin — cadastros e parâmetros do sistema
    |----------------------------------------------------------------------
    */
    Route::prefix('admin')->middleware(EnsureUserIsAdmin::class)->group(function () {

        Route::apiResource('materials', AdminMaterialController::class);

        Route::get('cost-settings/current', [CostSettingController::class, 'current']);
        Route::get('cost-settings', [CostSettingController::class, 'index']);
        Route::post('cost-settings', [CostSettingController::class, 'store']);

        Route::apiResource('users', UserController::class);
    });
});

<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\Admin\CompanyHourController;
use App\Http\Controllers\Api\Admin\CostSettingController;
use App\Http\Controllers\Api\Admin\EquipmentController;
use App\Http\Controllers\Api\Admin\FixedCostController;
use App\Http\Controllers\Api\Admin\MaterialController as AdminMaterialController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingWebhookController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\FinancialDashboardController;
use App\Http\Controllers\Api\InstallmentController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\Platform\PlatformDashboardController;
use App\Http\Controllers\Api\Platform\PlatformTenantController;
use App\Http\Controllers\Api\Platform\PlatformUserController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuoteApprovalController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\QuotePdfController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TechnicalSheetController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Middleware\EnsureSubscriptionIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsPlatformAdmin;
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
 * Cadastro de empresa — a porta de entrada do SaaS.
 *
 * Throttle apertado (5/min por IP) porque cada requisição bem-sucedida cria uma
 * empresa, um usuário, uma configuração de custos e quatro materiais. Sem
 * limite, um script encheria o banco em minutos, e quem paga a conta é você.
 *
 * O e-mail de confirmação sai daqui, mas não trava nada: ver RegisterController.
 */
Route::post('/register', RegisterController::class)->middleware('throttle:5,1');

/*
 * Tabela de preços.
 *
 * Pública porque é a vitrine: a página inicial a lê para montar os cartões de
 * plano, e ninguém que ainda está decidindo tem token para apresentar.
 *
 * Throttle folgado — a resposta não toca o banco e o frontend a guarda em cache
 * por hora, então 60/min por IP só existe para conter script bobo.
 */
Route::get('/plans', PlanController::class)->middleware('throttle:60,1');

/*
 * "Esqueci minha senha".
 *
 * Públicas por definição — quem chama não consegue autenticar. Até existirem, a
 * única recuperação possível era o operador da plataforma apertar o botão em
 * /api/platform, e o assinante ficava trancado para fora da própria empresa até
 * alguém atender o telefone.
 *
 * Throttle nas duas: a primeira dispara e-mail para terceiros (vetor de
 * incômodo) e a segunda aceita um token, o que a torna alvo de força bruta.
 * O broker do Laravel ainda impõe 60s entre pedidos para o mesmo e-mail.
 */
Route::post('/password/email', [PasswordResetController::class, 'sendLink'])
    ->middleware('throttle:6,1');

Route::post('/password/reset', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:6,1');

/*
 * Webhook de cobrança.
 *
 * Público porque quem chama é um servidor do gateway, que não tem token de
 * usuário. A autenticidade vem da assinatura HMAC do corpo, verificada dentro do
 * controller — é a única barreira entre esta rota e um botão anônimo de upgrade
 * de plano, e por isso está no contrato do PaymentGateway e não como detalhe.
 *
 * Sem throttle: gateways mandam rajadas legítimas ao reprocessar uma fila, e um
 * 429 vira reenvio em backoff — no limite, endpoint desativado pelo provedor.
 * A trava contra abuso aqui é a assinatura, não a contagem.
 */
Route::post('/webhooks/billing', BillingWebhookController::class);

/*
|--------------------------------------------------------------------------
| Autenticado
|--------------------------------------------------------------------------
*/

/*
 * EnsureSubscriptionIsActive vai no grupo inteiro, e não rota a rota: uma rota
 * de escrita criada amanhã já nasce cobrada. Ele só barra escrita — leitura e
 * exportação continuam abertas para quem está vencido. Ver a classe.
 */
Route::middleware(['auth:sanctum', EnsureSubscriptionIsActive::class])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    /*
    |----------------------------------------------------------------------
    | Assinatura da própria empresa
    |----------------------------------------------------------------------
    | O cancelamento aplica o direito de arrependimento do art. 49 do CDC:
    | dentro de sete dias corridos, estorno integral automático. A regra mora
    | no SubscriptionManager.
    */
    Route::get('/subscription', [SubscriptionController::class, 'show']);
    Route::post('/subscriptions/checkout', [SubscriptionController::class, 'checkout']);
    Route::post('/subscriptions/cancel', [SubscriptionController::class, 'cancel']);

    /*
     * Contexto da sessão: usuário + empresa + plano vigente + cotas.
     *
     * Numa chamada só, porque toda tela precisa das quatro coisas — e porque um
     * retrato tirado de uma leitura só não corre o risco de mostrar o plano de
     * antes com a cota de depois.
     */
    Route::get('/me', MeController::class);

    // Reenvio do link de confirmação. Throttle porque dispara e-mail.
    Route::post('/email/verification-notification', EmailVerificationController::class)
        ->middleware('throttle:6,1');

    /*
    |----------------------------------------------------------------------
    | A própria empresa — o que vai no cabeçalho das propostas
    |----------------------------------------------------------------------
    | Razão social, CNPJ, endereço, contatos e logotipo. Sem estes dados o PDF
    | comercial da Fase 5 sai sem identificação nenhuma. Leitura para qualquer
    | usuário, escrita só para o admin da empresa — ver CompanyController.
    */
    Route::get('company', [CompanyController::class, 'show']);
    Route::put('company', [CompanyController::class, 'update']);
    Route::get('company/logo', [CompanyController::class, 'showLogo']);
    Route::post('company/logo', [CompanyController::class, 'uploadLogo']);
    Route::delete('company/logo', [CompanyController::class, 'destroyLogo']);

    /*
    |----------------------------------------------------------------------
    | Conta — LGPD
    |----------------------------------------------------------------------
    | Exclusão definitiva da empresa e de tudo que pende dela. A autorização
    | (só o admin da própria empresa) e a confirmação por senha moram no
    | ExcluirContaRequest, junto do motivo de existirem.
    */
    Route::delete('/account', [AccountController::class, 'destroy']);

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

    /*
     * A cota do plano é declarada na rota (`quota:quotes`), nunca adivinhada
     * pela URI: renomear um caminho não pode desligar a cobrança em silêncio.
     * O middleware só age em POST — ver CheckTenantLimits.
     */
    Route::apiResource('quotes', QuoteController::class)->middleware('quota:quotes');

    /*
    |----------------------------------------------------------------------
    | ERP — cadastros, caixa e painel financeiro
    |----------------------------------------------------------------------
    | Rotas de usuário e não de admin: quem atende o cliente é quem orça e
    | quem lança o recebimento. Todas escopadas por empresa pelo TenantScope.
    */

    Route::apiResource('clients', ClientController::class)->middleware('quota:clients');
    Route::apiResource('suppliers', SupplierController::class);
    Route::apiResource('products', ProductController::class);

    /*
     * Aprovar é lançar dinheiro, não editar um campo — daí a rota própria.
     * Ver QuoteApprovalController.
     */
    /*
     * Motores de saída — Fase 5.
     *
     * A ficha é calculada no SERVIDOR e entregue pronta: duplicar a geometria
     * no Next.js criaria uma terceira implementação que a paridade não vigia.
     */
    Route::get('quotes/{quote}/technical-sheet', TechnicalSheetController::class);
    Route::get('quotes/{quote}/download-pdf', QuotePdfController::class);

    /*
     * Reeditar o rascunho.
     *
     * URL própria porque o que ela substitui é a ESPECIFICAÇÃO inteira, e não
     * um campo: o `update` do apiResource aceita status e observação soltos, e
     * misturar os dois num método que adivinha a intenção pelo payload faria um
     * PUT sem `components` apagar a ferragem em silêncio.
     */
    Route::put('quotes/{quote}/specification', [QuoteController::class, 'revise']);

    Route::post('quotes/{quote}/approve', QuoteApprovalController::class);
    Route::post('quotes/{quote}/promote-client', [QuoteApprovalController::class, 'promoteClient']);

    /*
     * A caixa aprovada vira produto de catálogo.
     *
     * Fica junto da aprovação, e não em `products`, porque é o ORÇAMENTO que dá
     * origem: o preço publicado é o que o cliente aceitou, e a regra que o
     * protege ("só aprovado") é sobre o estado da proposta.
     */
    Route::post('quotes/{quote}/publish-product', [QuoteApprovalController::class, 'publishProduct']);

    // A venda que liga o catálogo ao caixa: lança a entrada, gera as parcelas e
    // baixa o estoque numa transação só.
    Route::post('products/{product}/sell', [ProductController::class, 'sell']);

    Route::apiResource('transactions', TransactionController::class)
        ->only(['index', 'store', 'show', 'destroy']);

    Route::get('installments', [InstallmentController::class, 'index']);
    Route::post('installments/{installment}/settle', [InstallmentController::class, 'settle']);
    Route::delete('installments/{installment}/settle', [InstallmentController::class, 'unsettle']);

    Route::get('financial/dashboard', FinancialDashboardController::class);

    /*
    |----------------------------------------------------------------------
    | Admin — cadastros e parâmetros do sistema
    |----------------------------------------------------------------------
    */
    Route::prefix('admin')->middleware(EnsureUserIsAdmin::class)->group(function () {

        Route::apiResource('materials', AdminMaterialController::class)->middleware('quota:materials');

        /*
         * Parque de máquinas e o rateio da depreciação.
         *
         * A rota de impacto vem ANTES do apiResource: `equipment/{equipment}`
         * casaria com "equipment/depreciation-impact" e tentaria resolver
         * "depreciation-impact" como id, devolvendo 404 em vez do relatório.
         */
        Route::get('equipment/depreciation-impact', [EquipmentController::class, 'depreciationImpact']);
        Route::apiResource('equipment', EquipmentController::class)->parameters(['equipment' => 'equipment']);

        /*
         * Despesas fixas mensais e a hora-empresa que sai delas.
         *
         * A hora-empresa é GET e não persiste nada: o painel é um simulador,
         * e a maior parte das combinações que o usuário testa ele não quer
         * guardar. Ver CompanyHourController.
         */
        Route::apiResource('fixed-costs', FixedCostController::class);
        Route::get('company-hour', CompanyHourController::class);

        Route::get('cost-settings/current', [CostSettingController::class, 'current']);
        Route::get('cost-settings', [CostSettingController::class, 'index']);
        Route::post('cost-settings', [CostSettingController::class, 'store']);

        Route::apiResource('users', UserController::class);
    });

    /*
    |----------------------------------------------------------------------
    | Plataforma — a visão de quem OPERA o SaaS
    |----------------------------------------------------------------------
    | Prefixo próprio, e essa separação é a decisão de segurança mais
    | importante da Fase 6.
    |
    | O grupo `admin` acima é guardado por EnsureUserIsAdmin, que passa para o
    | dono de QUALQUER empresa assinante — depois da Fase 1, "admin" quase
    | sempre significa dono de uma empresa. Publicar o faturamento da
    | plataforma, a lista de todos os inquilinos e o mapa geográfico deles ali
    | dentro entregaria a cada cliente a carteira inteira do negócio.
    |
    | O que distingue os dois não é o papel, é o `tenant_id` nulo. Ver
    | User::isPlatformAdmin() e EnsureUserIsPlatformAdmin.
    */
    Route::prefix('platform')->middleware(EnsureUserIsPlatformAdmin::class)->group(function () {

        Route::get('dashboard', PlatformDashboardController::class);

        Route::get('tenants', [PlatformTenantController::class, 'index']);
        Route::get('tenants/{tenant}', [PlatformTenantController::class, 'show']);
        Route::patch('tenants/{tenant}/plan', [PlatformTenantController::class, 'updatePlan']);
        Route::patch('tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend']);

        Route::get('users', [PlatformUserController::class, 'index']);
        Route::post('users/{user}/force-logout', [PlatformUserController::class, 'forceLogout']);
        Route::post('users/{user}/password-reset', [PlatformUserController::class, 'sendPasswordReset']);
        Route::patch('users/{user}/active', [PlatformUserController::class, 'toggleActive']);
    });
});

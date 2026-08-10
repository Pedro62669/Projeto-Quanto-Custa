<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Rotinas diárias
|--------------------------------------------------------------------------
| Dependem de um único cron no servidor:
|   * * * * * cd /caminho && php artisan schedule:run >> /dev/null 2>&1
*/

/*
 * Expurgo dos registros de acesso (Marco Civil + LGPD).
 *
 * De madrugada porque apaga em lote e a tabela é escrita a cada requisição de
 * escrita do sistema inteiro.
 */
Schedule::command('compliance:expurgar-acessos')
    ->dailyAt('03:30')
    ->onOneServer();

/*
 * Reengajamento de quem sumiu.
 *
 * 09:00 e em dia útil: e-mail de terça de manhã é lido, e-mail de sábado às três
 * da manhã vira "não lido" na segunda. O conteúdo pede uma ação de trabalho
 * (revisar custos), então precisa chegar em horário de trabalho.
 *
 * withoutOverlapping porque o comando envia em série: uma fila grande com SMTP
 * lento pode atravessar a execução seguinte e disparar tudo duas vezes.
 */
/*
 * Fecha os períodos de teste vencidos, REBAIXANDO para o gratuito.
 *
 * De madrugada e antes do expediente: a empresa que perdeu o teste ontem já
 * acorda com as cotas certas, em vez de descobrir a mudança no meio do dia de
 * trabalho. A cota já está correta desde a virada por Tenant::planoVigente() —
 * este comando existe para os agregados SQL do painel de plataforma.
 */
Schedule::command('billing:encerrar-testes')
    ->dailyAt('04:00')
    ->onOneServer();

/*
 * Reconciliação dos webhooks de cobrança que chegaram e não foram aplicados.
 *
 * De hora em hora, e não diária: o caso que ela cobre é dinheiro que entrou no
 * cartão do cliente e não entrou no sistema. Esperar até a madrugada seguinte
 * significaria um dia inteiro de suporte com o cliente dizendo "eu paguei".
 */
Schedule::command('billing:reconciliar')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('app:send-engagement-emails')
    ->weekdays()
    ->at('09:00')
    ->onOneServer()
    ->withoutOverlapping();

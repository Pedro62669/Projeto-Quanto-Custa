<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reaplica eventos de cobrança que chegaram e não foram processados.
 *
 * Cumpre a promessa escrita no BillingWebhookController: quando `aplicarEvento`
 * falha, o registro fica com `processed_at` nulo e o erro gravado, "para a
 * reconciliação achar". Sem este comando, essa frase era só uma intenção — e o
 * caso que ela descreve é o pior possível: o dinheiro entrou no cartão do
 * cliente e não entrou no sistema.
 *
 * Reprocessar é seguro porque `SubscriptionManager::aplicarEvento()` é
 * idempotente por construção: cada ramo dele foi escrito para rodar duas vezes
 * com o mesmo resultado.
 *
 * A janela de carência existe para não competir com o próprio gateway. Todo
 * provedor reenvia o que não recebeu 2xx; se este comando atacasse eventos
 * recém-falhados, ele e o reenvio disputariam o mesmo registro. Dez minutos é
 * mais do que qualquer política de retry inicial.
 */
class ReconciliaWebhooks extends Command
{
    protected $signature = 'billing:reconciliar
                            {--carencia=10 : Minutos de espera antes de reprocessar}
                            {--limite=100 : Teto de eventos por execução}';

    protected $description = 'Reprocessa webhooks de cobrança recebidos mas não aplicados';

    public function handle(PaymentGateway $gateway, SubscriptionManager $manager): int
    {
        $carencia = max((int) $this->option('carencia'), 1);
        $limite = max((int) $this->option('limite'), 1);

        $pendentes = WebhookEvent::query()
            ->whereNull('processed_at')
            ->where('gateway', $gateway->name())
            ->where('created_at', '<=', now()->subMinutes($carencia))
            ->orderBy('id')
            ->limit($limite)
            ->get();

        if ($pendentes->isEmpty()) {
            $this->info('Nenhum evento pendente de reconciliação.');

            return self::SUCCESS;
        }

        $aplicados = 0;
        $falhas = 0;

        foreach ($pendentes as $registro) {
            $evento = $gateway->interpretaEvento($registro->payload);

            if ($evento === null) {
                /*
                 * Payload que o driver não sabe mais ler — acontece quando o
                 * provedor muda o formato. Marca como processado para não ficar
                 * eternamente na fila, mas deixa o motivo escrito: o registro
                 * continua sendo a prova do que chegou.
                 */
                $registro->forceFill([
                    'processed_at' => now(),
                    'error' => 'Evento não interpretável pelo driver atual; ignorado na reconciliação.',
                ])->save();

                $this->warn("#{$registro->id} ({$registro->type}) — payload não interpretável.");

                continue;
            }

            try {
                $manager->aplicarEvento($evento);

                $registro->forceFill(['processed_at' => now(), 'error' => null])->save();
                $aplicados++;

                $this->line("#{$registro->id} {$registro->type} — aplicado.");
            } catch (Throwable $e) {
                $falhas++;

                $registro->forceFill(['error' => mb_substr($e->getMessage(), 0, 2000)])->save();

                $this->warn("#{$registro->id} {$registro->type} — falhou de novo: {$e->getMessage()}");
            }
        }

        $this->info("Aplicados: {$aplicados}. Ainda falhando: {$falhas}.");

        /*
         * Falha persistente devolve FAILURE para que o agendador registre e
         * alguém olhe. Um evento que não aplica duas vezes seguidas não se
         * resolve sozinho na terceira.
         */
        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }
}

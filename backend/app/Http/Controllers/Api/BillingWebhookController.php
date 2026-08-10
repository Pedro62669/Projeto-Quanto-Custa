<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recebe os avisos de cobrança do gateway.
 *
 * Rota PÚBLICA — não passa por auth:sanctum, porque quem chama é um servidor da
 * Stripe ou do Pagar.me, que não tem token de usuário nenhum. Isso torna a
 * verificação de assinatura a única coisa entre este endpoint e um botão
 * anônimo de "me promova para Pro": sem ela, qualquer POST com o corpo certo
 * mudaria o plano de qualquer empresa.
 */
class BillingWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PaymentGateway $gateway,
        SubscriptionManager $manager,
    ): JsonResponse {
        /*
         * getContent() e não $request->all(): a assinatura é calculada sobre os
         * BYTES enviados. Reserializar o array decodificado muda espaços, ordem
         * de chaves e escapes, e o HMAC nunca mais bate — é o erro que faz todo
         * webhook "funcionar em teste e falhar em produção".
         */
        $corpo = $request->getContent();
        $assinatura = $request->header((string) config('billing.signature_header'));

        if (! $gateway->verificaAssinatura($corpo, $assinatura)) {
            Log::warning('Webhook de cobrança com assinatura inválida', [
                'ip' => $request->ip(),
                'gateway' => $gateway->name(),
            ]);

            return response()->json(['message' => 'Assinatura inválida.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($corpo, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Corpo inválido.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $evento = $gateway->interpretaEvento($payload);

        if ($evento === null) {
            /*
             * 200 para um evento que não interessa.
             *
             * Devolver erro faria o gateway reenviar em backoff exponencial por
             * dias — e alguns provedores desativam o endpoint depois de tantas
             * falhas seguidas, o que derrubaria também os eventos que importam.
             */
            return response()->json(['message' => 'Evento ignorado.']);
        }

        /*
         * A trava de idempotência é o INSERT, não um SELECT antes dele.
         *
         * Todo gateway reenvia quando não recebe 2xx a tempo, e "a tempo"
         * inclui o dia em que o banco ficou lento — então dois webhooks do mesmo
         * evento podem estar em voo ao mesmo tempo. Um "já existe?" seguido de
         * "então grava" perde essa corrida; o índice único do banco, não.
         * Aplicar duas vezes significaria estender o período duas vezes ou
         * estornar dinheiro já devolvido.
         */
        try {
            $registro = WebhookEvent::create([
                'gateway' => $gateway->name(),
                'external_id' => $evento->externalId,
                'type' => $evento->type->value,
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Evento já processado.']);
        }

        try {
            $manager->aplicarEvento($evento);

            $registro->forceFill(['processed_at' => now()])->save();
        } catch (Throwable $e) {
            /*
             * Guarda o erro e devolve 500 DE PROPÓSITO: o gateway reenvia, e o
             * registro fica com processed_at nulo para a reconciliação achar.
             * Engolir a falha com 200 significaria um pagamento que entrou no
             * cartão do cliente e nunca no sistema.
             */
            $registro->forceFill(['error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            Log::error('Falha ao aplicar webhook de cobrança', [
                'external_id' => $evento->externalId,
                'type' => $evento->type->value,
                'erro' => $e->getMessage(),
            ]);

            return response()->json(
                ['message' => 'Falha ao processar o evento.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json(['message' => 'Processado.']);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Salvar um orçamento = simular + dados do cliente.
 *
 * Herda as regras de SimulateQuoteRequest para garantir que a especificação
 * validada no preview seja EXATAMENTE a validada na gravação.
 */
class StoreQuoteRequest extends SimulateQuoteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            /*
             * O cliente CADASTRADO, quando houver um.
             *
             * Opcional de propósito: muita venda de cartonagem fecha com um nome
             * e um WhatsApp, e exigir cadastro completo para orçar travaria o
             * caminho mais comum. Mas quando ele vem, é ele que manda — ver
             * QuoteController::store().
             *
             * Sem `Rule::exists` aqui, e isso não é esquecimento. A regra
             * consulta a tabela crua, por fora do Eloquent e portanto por fora
             * do TenantScope: aceitaria o id de um cliente de outra assinante, e
             * o orçamento gravado devolveria o nome dele na resposta. Quem
             * confere é o findOrFail escopado no controller, exatamente como
             * QuoteApprovalController já faz e documenta.
             */
            'client_id' => ['nullable', 'integer'],

            /*
             * Continua obrigatório mesmo com `client_id`.
             *
             * O nome é o SNAPSHOT do que foi combinado — a proposta impressa diz
             * "Papelaria Silva", e renomear o cadastro amanhã não pode reescrever
             * o que o cliente assinou ontem. Quando o id vem, o controller
             * preenche este campo a partir do registro e o que o navegador
             * mandou é descartado.
             */
            'client_name' => ['required', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'client_document' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}

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
            'client_name' => ['required', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'client_document' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}

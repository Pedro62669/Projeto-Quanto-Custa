<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Reeditar o rascunho: a especificação inteira, e só ela.
 *
 * Herda de SimulateQuoteRequest e NÃO de StoreQuoteRequest, e a diferença é o
 * cliente. Reeditar corrige a caixa — medidas, material, ferragem —, não troca
 * o destinatário: quem for reeditado já tem cliente gravado, e aceitar os
 * campos aqui abriria caminho para uma correção de medida levar junto uma troca
 * de cliente que ninguém pediu.
 *
 * Trocar o cliente de um orçamento é outra operação, e hoje ela não existe de
 * propósito — na prática se resolve duplicando para o cliente certo.
 */
class ReviseQuoteRequest extends SimulateQuoteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // A observação acompanha a caixa e não o cliente: "sem verniz na
            // tampa" descreve o que vai ser produzido.
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}

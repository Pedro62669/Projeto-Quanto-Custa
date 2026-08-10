<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de um evento recebido do gateway.
 *
 * Serve a duas coisas ao mesmo tempo: a trava de idempotência (pelo unique
 * gateway+external_id) e o rastro para depurar a cobrança quando o cliente
 * ligar dizendo que pagou. Guarda o payload cru justamente por causa do segundo
 * caso — reconstruir o que o gateway mandou a partir dos efeitos é impossível.
 */
class WebhookEvent extends Model
{
    protected $fillable = [
        'gateway', 'external_id', 'type', 'payload', 'processed_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}

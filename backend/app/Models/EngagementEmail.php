<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de um disparo de e-mail de engajamento.
 *
 * Existe para responder uma única pergunta, feita todo dia pelo cron: "este
 * usuário já recebeu algo recentemente?". Sem ela, o critério "sumiu há mais de
 * 10 dias" continua verdadeiro no dia 11, no 12, no 13 — e quem tirou férias
 * volta para trinta e-mails iguais.
 */
class EngagementEmail extends Model
{
    protected $fillable = ['user_id', 'tenant_id', 'type', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

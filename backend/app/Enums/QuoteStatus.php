<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ciclo de vida de um orçamento.
 *
 * Os valores já existiam como texto livre na coluna `status` desde a criação da
 * tabela; o enum apenas dá nome ao vocabulário que estava num comentário de
 * migration. Ele virou necessário na Fase 4: `Approved` deixou de ser um rótulo
 * e passou a ser um GATILHO — é ele que lança a venda no caixa.
 */
enum QuoteStatus: string
{
    /** Rascunho: simulação salva, ainda não enviada ao cliente. */
    case Draft = 'draft';

    /** Enviado ao cliente, aguardando resposta. */
    case Sent = 'sent';

    /**
     * Fechado. Gera a transação de entrada e as parcelas.
     *
     * É o único estado com efeito colateral financeiro, e por isso a transição
     * tem endpoint próprio em vez de ser um PATCH de campo — ver
     * QuoteApprovalController.
     */
    case Approved = 'approved';

    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Sent => 'Enviado',
            self::Approved => 'Aprovado',
            self::Rejected => 'Recusado',
        };
    }

    /** Já virou dinheiro no caixa? */
    public function isClosed(): bool
    {
        return $this === self::Approved;
    }
}

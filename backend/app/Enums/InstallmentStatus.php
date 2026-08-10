<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de uma parcela.
 *
 * Dois estados, e não três: não existe "atrasada" guardada no banco. Atraso é
 * uma função da data de HOJE contra o vencimento, e gravá-lo exigiria um job
 * varrendo a tabela toda noite para manter a coluna verdadeira — com o efeito
 * de que, entre duas execuções, o sistema mentiria. Ver Installment::isOverdue().
 */
enum InstallmentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Em aberto',
            self::Completed => 'Quitada',
        };
    }
}

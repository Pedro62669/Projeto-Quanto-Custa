<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Direção do dinheiro.
 *
 * Dois valores e não um sinal negativo no valor: `amount` guarda sempre um
 * número positivo, e o sentido mora aqui. Somar despesa como valor negativo
 * funcionaria até alguém esquecer o sinal num lançamento manual — e um relatório
 * com uma despesa positiva no meio das receitas não denuncia o erro, só entrega
 * um faturamento inflado.
 */
enum TransactionType: string
{
    /** Entrada: venda de orçamento, revenda de produto, aporte. */
    case Entry = 'entry';

    /** Saída: compra de insumo, despesa fixa, retirada. */
    case Exit = 'exit';

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Entrada',
            self::Exit => 'Saída',
        };
    }

    /** O verbo que a interface usa para quitar a parcela. */
    public function settleLabel(): string
    {
        return match ($this) {
            self::Entry => 'Recebido',
            self::Exit => 'Pago',
        };
    }
}

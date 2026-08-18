<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * O que é um produto do catálogo.
 *
 * A tabela nasceu com um comentário — "produto pronto para revenda casada com a
 * embalagem" — e nenhuma relação com coisa alguma: nem com orçamento, nem com
 * cliente, nem com o caixa. Guardava custo, preço e estoque num canto do
 * sistema que não conversava com o resto.
 *
 * A distinção existe porque as duas coisas nascem de formas diferentes e a
 * interface precisa saber qual é qual.
 */
enum ProductKind: string
{
    /**
     * Caixa pronta: nasceu de um orçamento aprovado.
     *
     * O preço não é digitado — vem do `unit_price` que o motor calculou e o
     * cliente aprovou. É o que permite responder "quanto custa aquela caixa que
     * fizemos para a joalheria?" sem refazer a conta, e vender de prateleira um
     * modelo que já foi produzido uma vez.
     */
    case Box = 'box';

    /**
     * Mercadoria avulsa: fita, laço, tag, sacola.
     *
     * Comprada pronta e revendida junto da embalagem ou sozinha. Não tem
     * orçamento por trás, e o preço é digitado no cadastro.
     */
    case Merchandise = 'merchandise';

    public function label(): string
    {
        return match ($this) {
            self::Box => 'Caixa pronta',
            self::Merchandise => 'Mercadoria',
        };
    }

    /** Nasce de um orçamento e não é criada à mão. */
    public function fromQuote(): bool
    {
        return $this === self::Box;
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A natureza do lançamento — o eixo do gráfico de distribuição de receitas.
 *
 * Enum e não string livre porque é campo de AGRUPAMENTO: com texto livre,
 * "venda", "Venda" e "vendas" viram três fatias no mesmo gráfico e ninguém
 * descobre por que os percentuais não fecham.
 *
 * `QuoteSale` tem um privilégio sobre as outras: é a única categoria cujo custo
 * variável o sistema conhece peça a peça (o orçamento gravou material,
 * revestimento e ferragem). Por isso é ela que sustenta a margem de
 * contribuição do ponto de equilíbrio — ver FinancialEngine.
 */
enum TransactionCategory: string
{
    /** Venda de embalagem originada de um orçamento aprovado. */
    case QuoteSale = 'quote_sale';

    /** Revenda de produto pronto do estoque. */
    case ProductSale = 'product_sale';

    /** Compra de matéria-prima de fornecedor. */
    case MaterialPurchase = 'material_purchase';

    /** Despesa fixa lançada no caixa (aluguel, contador, energia). */
    case FixedCost = 'fixed_cost';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::QuoteSale => 'Venda de embalagem',
            self::ProductSale => 'Revenda de produto',
            self::MaterialPurchase => 'Compra de insumo',
            self::FixedCost => 'Despesa fixa',
            self::Other => 'Outro',
        };
    }

    /** A direção natural desta categoria — usada para validar o lançamento. */
    public function naturalType(): TransactionType
    {
        return match ($this) {
            self::QuoteSale, self::ProductSale => TransactionType::Entry,
            self::MaterialPurchase, self::FixedCost => TransactionType::Exit,
            // A única que aceita os dois sentidos: serve de escape para
            // estorno, aporte de sócio e o que o negócio inventar.
            self::Other => TransactionType::Entry,
        };
    }

    /** Categorias cujo custo variável o sistema consegue apurar sozinho. */
    public function hasKnownVariableCost(): bool
    {
        return $this === self::QuoteSale;
    }
}

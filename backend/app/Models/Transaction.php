<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um lançamento no livro caixa — o FATO gerador.
 *
 * O valor a receber/pagar não mora aqui: mora nas parcelas. `amount` é o total
 * combinado, e a soma das parcelas tem que fechar com ele — há teste fixando
 * isso, porque é a invariante que sustenta os dois números do painel.
 *
 * @property TransactionType $type
 * @property TransactionCategory $category
 * @property float $amount
 */
class Transaction extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'client_id', 'supplier_id', 'quote_id', 'product_id',
        'type', 'category', 'amount', 'description', 'transaction_date',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'category' => TransactionCategory::class,
            'amount' => 'float',
            'transaction_date' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** O produto revendido. Null em tudo que não é venda de catálogo. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('installment_number');
    }

    public function scopeEntries(Builder $query): Builder
    {
        return $query->where('type', TransactionType::Entry);
    }

    public function scopeExits(Builder $query): Builder
    {
        return $query->where('type', TransactionType::Exit);
    }

    /**
     * O custo variável desta venda — insumos, não mão de obra.
     *
     * Só orçamentos sabem responder: eles gravaram material, revestimento e
     * ferragem peça a peça. Uma revenda de produto teria o `cost_price` do
     * item, mas a transação não guarda QUAL produto nem quantos — e inventar
     * um custo aqui contaminaria a margem de contribuição com um chute.
     *
     * Devolve null quando o custo é desconhecido, e o motor financeiro exclui
     * essas vendas do cálculo em vez de tratá-las como custo zero (o que
     * inflaria a margem e rebaixaria o ponto de equilíbrio — o erro perigoso,
     * porque faz o negócio parecer mais saudável do que é).
     */
    public function variableCost(): ?float
    {
        if (! $this->category->hasKnownVariableCost() || $this->quote === null) {
            return null;
        }

        $quote = $this->quote;

        $porUnidade = $quote->material_cost + $quote->wrap_cost + $quote->hardware_cost;

        return round($porUnidade * $quote->quantity, 2);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductKind;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Produto pronto para revenda casada com a embalagem.
 *
 * @property float $cost_price
 * @property float $sale_price
 */
class Product extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'kind', 'quote_id', 'name', 'sku', 'cost_price', 'sale_price',
        'stock_quantity', 'description', 'is_active',
    ];

    protected $attributes = [
        'kind' => ProductKind::Merchandise->value,
        'cost_price' => 0,
        'sale_price' => 0,
        'stock_quantity' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'cost_price' => 'float',
            'sale_price' => 'float',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * O orçamento que originou esta caixa. Null em mercadoria.
     *
     * Nullable também em caixa cujo orçamento foi excluído: o produto continua
     * no catálogo e continua sendo vendido, e o que se perde é o atalho para a
     * proposta.
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** As vendas deste produto no livro-caixa. */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeOfKind(Builder $query, ProductKind $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * Margem da revenda, em % sobre o preço de venda.
     *
     * Sobre a VENDA e não sobre o custo: é a margem que o dono do negócio
     * consegue comparar com a das embalagens, que o motor de preço já reporta
     * dessa forma (`effective_margin_percent`). Duas margens no mesmo painel
     * medidas por bases diferentes seria uma armadilha de leitura.
     */
    public function marginPercent(): float
    {
        if ($this->sale_price <= 0) {
            return 0.0;
        }

        return round(($this->sale_price - $this->cost_price) / $this->sale_price * 100, 2);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('stock_quantity', '>', 0);
    }
}

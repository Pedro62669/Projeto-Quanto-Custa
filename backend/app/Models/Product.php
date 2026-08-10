<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'tenant_id', 'name', 'sku', 'cost_price', 'sale_price',
        'stock_quantity', 'description', 'is_active',
    ];

    protected $attributes = [
        'cost_price' => 0,
        'sale_price' => 0,
        'stock_quantity' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'float',
            'sale_price' => 'float',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
        ];
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

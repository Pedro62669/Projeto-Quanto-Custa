<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComponentRole;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma peça retangular do modelo livre.
 *
 * O que ela NÃO guarda: preço. O custo por m² vem do material apontado, e a
 * perda vem do `default_waste_percent` dele — congelar os dois aqui faria a
 * peça envelhecer com números que o cadastro já corrigiu. O congelamento
 * acontece uma vez só, no `pricing_snapshot` do orçamento, que é onde ele tem
 * significado: ali o preço foi combinado com o cliente.
 *
 * @property ComponentRole $component_role
 */
class QuoteCustomPart extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'quote_id', 'material_id',
        'name', 'component_role', 'width_mm', 'length_mm', 'quantity',
    ];

    protected $attributes = [
        'component_role' => ComponentRole::Structure->value,
        'quantity' => 1,
    ];

    protected function casts(): array
    {
        return [
            'component_role' => ComponentRole::class,
            'width_mm' => 'integer',
            'length_mm' => 'integer',
            'quantity' => 'integer',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Área líquida da peça, em m², já multiplicada pela quantidade POR CAIXA.
     *
     * Líquida: sem a perda. Quem aplica a perda é o motor, junto com todo o
     * resto do cálculo — aplicá-la aqui esconderia dentro de um accessor uma
     * regra que precisa estar visível no PricingEngine, e que o gêmeo em
     * TypeScript tem de espelhar.
     */
    public function netAreaM2(): float
    {
        return ($this->width_mm * $this->length_mm) / 1_000_000.0 * $this->quantity;
    }
}

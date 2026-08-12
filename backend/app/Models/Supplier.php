<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fornecedor de insumos.
 */
class Supplier extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'name', 'contact_name', 'phone', 'email',
        'state', 'city', 'is_active',
    ];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * O que este fornecedor vende.
     *
     * Ordenado por nome no próprio relacionamento: a lista aparece como
     * etiquetas na tabela e no formulário, e ordem de chegada no banco faria as
     * mesmas etiquetas trocarem de lugar entre um carregamento e outro.
     */
    public function materials(): BelongsToMany
    {
        return $this->belongsToMany(Material::class)->orderBy('materials.name');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

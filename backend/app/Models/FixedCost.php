<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma despesa fixa mensal da empresa.
 *
 * @property float $monthly_amount
 */
class FixedCost extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /** Ver CostSetting: o `creating` da trait sobrescreve tenant_id vindo de request. */
    protected $fillable = ['tenant_id', 'name', 'monthly_amount', 'is_active'];

    /** Espelha o default do banco — mesma razão que em User e Material. */
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'monthly_amount' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

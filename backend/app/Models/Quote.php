<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BoxModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Quote extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'box_model' => BoxModel::class,
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'depth_mm' => 'integer',
            'quantity' => 'integer',
            'waste_percent' => 'float',
            'production_minutes_per_unit' => 'float',
            'profit_margin_percent' => 'float',
            'area_m2_per_unit' => 'float',
            'area_m2_total' => 'float',
            'material_cost' => 'float',
            'labor_cost' => 'float',
            'machine_cost' => 'float',
            'energy_cost' => 'float',
            'overhead_cost' => 'float',
            'unit_cost' => 'float',
            'unit_price' => 'float',
            'total_cost' => 'float',
            'total_price' => 'float',
            'profit_amount' => 'float',
            'pricing_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function costSetting(): BelongsTo
    {
        return $this->belongsTo(CostSetting::class);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Gera a referência sequencial por ano (ORC-2026-000042).
     *
     * Incrementa a linha do ano em `quote_counters` sob lockForUpdate(): duas
     * requisições simultâneas serializam no banco e recebem números distintos.
     * COUNT(*)+1 sobre `quotes` sofreria corrida e violaria o índice único de
     * `reference`.
     */
    public static function nextReference(): string
    {
        $year = now()->year;

        $number = DB::transaction(function () use ($year) {
            // insertOrIgnore: cria a linha do ano na primeira chamada sem
            // estourar erro se outra requisição a criou antes.
            DB::table('quote_counters')->insertOrIgnore(['year' => $year, 'last_number' => 0]);

            $current = DB::table('quote_counters')
                ->where('year', $year)
                ->lockForUpdate()
                ->value('last_number');

            $next = $current + 1;

            DB::table('quote_counters')
                ->where('year', $year)
                ->update(['last_number' => $next]);

            return $next;
        });

        return sprintf('ORC-%d-%06d', $year, $number);
    }

    protected static function booted(): void
    {
        static::creating(function (self $quote) {
            $quote->reference ??= static::nextReference();
        });
    }
}

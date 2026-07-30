<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'energy_tariff_per_kwh', 'machine_hour_rate', 'machine_power_kw',
        'labor_hour_rate', 'overhead_percent', 'tax_percent',
        'default_profit_margin_percent', 'currency', 'effective_from', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'energy_tariff_per_kwh' => 'float',
            'machine_hour_rate' => 'float',
            'machine_power_kw' => 'float',
            'labor_hour_rate' => 'float',
            'overhead_percent' => 'float',
            'tax_percent' => 'float',
            'default_profit_margin_percent' => 'float',
            'effective_from' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Chave única do cache da configuração vigente. */
    private const CACHE_KEY = 'cost_settings:current';

    /**
     * Configuração vigente: a mais recente cuja vigência já começou.
     *
     * Cacheada porque é lida em toda simulação de preço (a rota mais quente da
     * API). O cache é invalidado no `saved` do próprio model — ver booted().
     *
     * Guardamos o ARRAY de atributos, não o objeto Eloquent. Dois motivos:
     *
     *  1. O Laravel 13 desserializa o cache com uma allowlist de classes que,
     *     por padrão (`cache.serializable_classes => false`), é vazia — um
     *     model cacheado voltaria como __PHP_Incomplete_Class. Cachear um
     *     array respeita esse default de segurança em vez de abrir exceção nele.
     *  2. Um model serializado carrega junto conexão, relações e estado de
     *     dirty; um array é um dado inerte, que é tudo o que precisamos aqui.
     */
    public static function current(): self
    {
        $attributes = cache()->rememberForever(self::CACHE_KEY, function (): array {
            $setting = static::query()
                ->where('effective_from', '<=', now())
                ->latest('effective_from')
                ->first();

            if (! $setting) {
                throw new \DomainException(
                    'Nenhuma configuração de custos vigente. Cadastre os custos fixos antes de orçar.'
                );
            }

            return $setting->getAttributes();
        });

        // newFromBuilder (e não `new self`) marca o model como existente e
        // pula os mutators de escrita: é a hidratação que o próprio Eloquent
        // usa ao ler do banco.
        return (new self)->newFromBuilder($attributes);
    }

    protected static function booted(): void
    {
        static::saved(fn () => cache()->forget(self::CACHE_KEY));
        static::deleted(fn () => cache()->forget(self::CACHE_KEY));
    }
}

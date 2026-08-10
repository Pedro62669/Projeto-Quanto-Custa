<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostSetting extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /**
     * `tenant_id` é preenchível, e isso é seguro por causa da ordem dos eventos:
     * o `creating` da trait sobrescreve o campo depois do fill(), então um valor
     * vindo de request autenticado nunca chega ao banco. Preenchível existe para
     * quem grava SEM usuário logado — seeder e factory, que precisam declarar a
     * empresa explicitamente.
     */
    protected $fillable = [
        'tenant_id',
        'energy_tariff_per_kwh', 'machine_hour_rate', 'machine_power_kw',
        'labor_hour_rate', 'overhead_percent', 'tax_percent',
        'default_profit_margin_percent', 'currency', 'effective_from', 'created_by',

        // Modo hora-empresa — ver a migration 2026_08_09_160001.
        'use_company_hour', 'company_hours_per_day', 'company_days_per_month',
        'company_efficiency_percent', 'company_includes_depreciation',
        'monthly_production_volume',
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

            'use_company_hour' => 'boolean',
            'company_hours_per_day' => 'float',
            'company_days_per_month' => 'float',
            'company_efficiency_percent' => 'integer',
            'company_includes_depreciation' => 'boolean',
            'monthly_production_volume' => 'integer',
        ];
    }

    /**
     * Espelha os defaults do banco — mesma razão que em User e Material.
     *
     * Sem isto, uma configuração criada sem os campos do modo carregaria null
     * em memória, e `use_company_hour` nulo passaria por falso em alguns
     * lugares e por "indefinido" em outros.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'use_company_hour' => false,
        'company_hours_per_day' => 8.00,
        'company_days_per_month' => 22.0,
        'company_efficiency_percent' => 85,
        'company_includes_depreciation' => true,
        'monthly_production_volume' => 75,
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Prefixo do cache; a chave completa leva a empresa — ver cacheKey(). */
    private const CACHE_PREFIX = 'cost_settings:current:';

    /**
     * Uma chave de cache POR EMPRESA.
     *
     * A chave era única e global, e essa é a armadilha do multi-inquilino que o
     * TenantScope sozinho não pega: o cache responde ANTES da query existir,
     * então o escopo nunca chega a ser aplicado. Com chave única, a primeira
     * empresa a orçar no dia populava o cache e todas as outras passavam a ser
     * precificadas com os custos dela — preço errado e, pior, os parâmetros
     * financeiros de um assinante servidos a outro.
     */
    private static function cacheKey(?int $tenantId): string
    {
        return self::CACHE_PREFIX.($tenantId ?? 'sem-tenant');
    }

    /**
     * Configuração vigente da empresa: a mais recente cuja vigência já começou.
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
     *
     * @param  ?int  $tenantId  Empresa explícita, para trabalho de plataforma.
     *                          Omitido, usa a do usuário autenticado.
     */
    public static function current(?int $tenantId = null): self
    {
        $tenantId ??= TenantScope::tenantAtual();

        $attributes = cache()->rememberForever(
            self::cacheKey($tenantId),
            function () use ($tenantId): array {
                /*
                 * Filtro explícito em vez do escopo automático: assim a leitura
                 * não depende de quem está autenticado no instante da query, e
                 * o valor cacheado corresponde exatamente à chave usada.
                 */
                $query = static::query()->withoutGlobalScope(TenantScope::class);

                if ($tenantId !== null) {
                    $query->where('tenant_id', $tenantId);
                }

                $setting = $query
                    ->where('effective_from', '<=', now())
                    ->latest('effective_from')
                    ->first();

                if (! $setting) {
                    throw new \DomainException(
                        'Nenhuma configuração de custos vigente. Cadastre os custos fixos antes de orçar.'
                    );
                }

                return $setting->getAttributes();
            }
        );

        // newFromBuilder (e não `new self`) marca o model como existente e
        // pula os mutators de escrita: é a hidratação que o próprio Eloquent
        // usa ao ler do banco.
        return (new self)->newFromBuilder($attributes);
    }

    protected static function booted(): void
    {
        /*
         * Invalida a chave da empresa da linha E a chave sem tenant: uma
         * leitura de console (seeder, comando) pode ter cacheado justamente
         * esta linha sob 'sem-tenant', e deixá-la para trás serviria o valor
         * velho para sempre — o cache é rememberForever.
         */
        $esquecer = function (self $setting): void {
            $tenantId = $setting->tenant_id === null ? null : (int) $setting->tenant_id;

            cache()->forget(self::cacheKey($tenantId));
            cache()->forget(self::cacheKey(null));
        };

        static::saved($esquecer);
        static::deleted($esquecer);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BoxModel;
use App\Enums\QuoteStatus;
use App\Models\Scopes\TenantScope;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Quote extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * Espelha o default do banco — mesma razão que em User e Material.
     *
     * Sem isto, um orçamento recém-criado sem `status` explícito carrega null
     * em memória (o default só é aplicado pelo banco, e create() não relê a
     * linha). O `status` deixou de ser rótulo na Fase 4: é ele que decide se a
     * venda já foi lançada no caixa, e um null aí faz a comparação com
     * QuoteStatus::Approved passar por "não aprovado" sem que ninguém saiba.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => QuoteStatus::Draft->value,
    ];

    protected function casts(): array
    {
        return [
            'box_model' => BoxModel::class,
            'status' => QuoteStatus::class,
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

    /**
     * Cliente cadastrado, quando houver.
     *
     * Nullable e convivendo com `client_name`/`client_email`: os campos de
     * texto são o SNAPSHOT do que foi combinado. Corrigir o cadastro amanhã não
     * pode reescrever a proposta assinada ontem — mesma filosofia do
     * `pricing_snapshot`.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** A venda lançada no caixa quando este orçamento foi aprovado. */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Peças medidas à mão — só existem no modelo livre.
     *
     * Ordenadas por papel e depois por id: estrutura antes de revestimento é a
     * ordem em que a peça é montada, e é a ordem em que a ficha técnica precisa
     * listar para quem está na bancada.
     */
    public function customParts(): HasMany
    {
        return $this->hasMany(QuoteCustomPart::class)
            ->orderBy('component_role')
            ->orderBy('id');
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Gera a referência sequencial por empresa e ano (ORC-2026-000042).
     *
     * Incrementa a linha (tenant, ano) em `quote_counters` sob lockForUpdate():
     * duas requisições simultâneas serializam no banco e recebem números
     * distintos. COUNT(*)+1 sobre `quotes` sofreria corrida e violaria o índice
     * único de `reference`.
     *
     * A contagem é POR EMPRESA desde o multi-inquilino. Um contador global
     * daria a cada assinante uma numeração cheia de buracos e denunciaria o
     * volume da plataforma inteira: quem recebesse o ORC-2026-000500 saberia
     * que outras 499 propostas foram emitidas por outras empresas.
     */
    public static function nextReference(int $tenantId): string
    {
        $year = now()->year;

        $number = DB::transaction(function () use ($tenantId, $year) {
            // insertOrIgnore: cria a linha do par na primeira chamada sem
            // estourar erro se outra requisição a criou antes.
            DB::table('quote_counters')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'year' => $year,
                'last_number' => 0,
            ]);

            $current = DB::table('quote_counters')
                ->where('tenant_id', $tenantId)
                ->where('year', $year)
                ->lockForUpdate()
                ->value('last_number');

            $next = $current + 1;

            DB::table('quote_counters')
                ->where('tenant_id', $tenantId)
                ->where('year', $year)
                ->update(['last_number' => $next]);

            return $next;
        });

        return sprintf('ORC-%d-%06d', $year, $number);
    }

    protected static function booted(): void
    {
        /*
         * Roda DEPOIS do creating da trait, que é registrado no boot das traits
         * — antes deste. É essa ordem que garante tenant_id preenchido aqui;
         * a referência precisa saber de qual empresa é a sequência.
         */
        static::creating(function (self $quote) {
            if ($quote->tenant_id === null) {
                throw new \DomainException(
                    'Orçamento sem empresa: não há sequência de referência para numerá-lo.'
                );
            }

            $quote->reference ??= static::nextReference((int) $quote->tenant_id);
        });
    }

    /**
     * Orçamentos de uma empresa específica, ignorando o escopo automático.
     *
     * Para trabalho de plataforma (suporte, relatório consolidado), onde o
     * operador não pertence à empresa cujos dados precisa ler.
     */
    public function scopeDoTenant(Builder $query, int $tenantId): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId);
    }
}

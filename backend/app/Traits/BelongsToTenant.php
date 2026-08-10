<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tudo que pertence a uma empresa: filtra a leitura e carimba a escrita.
 *
 * Os dois lados são necessários e resolvem problemas diferentes. O scope impede
 * LER o dado alheio; o `creating` impede GRAVAR no vizinho. Só o primeiro
 * deixaria a porta aberta para escrita cruzada, que é a metade mais silenciosa
 * do problema — o atacante nem veria o resultado, mas os dados estariam lá.
 *
 * @property int $tenant_id
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $tenantId = TenantScope::tenantAtual();

            if ($tenantId === null) {
                // Sem empresa corrente (seeder, comando, admin de plataforma):
                // respeita o que veio, e o NOT NULL do banco cobra se faltar.
                return;
            }

            /*
             * Atribuição incondicional, e não `??=`.
             *
             * Quote usa $guarded = ['id'], então `tenant_id` é preenchível em
             * massa: um POST com "tenant_id": 7 no corpo gravaria o orçamento
             * na empresa 7. Sobrescrever sempre torna o campo inerte na entrada
             * — o valor do usuário logado é o único que chega ao banco.
             */
            $model->setAttribute('tenant_id', $tenantId);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Escotilha explícita para atravessar empresas.
     *
     * Existe para o trabalho legítimo de plataforma — relatório consolidado,
     * rotina de cobrança, migração. É verbosa de propósito: quem escreve
     * `semEscopoDeTenant()` está declarando a intenção por escrito, e quem
     * revisa o diff consegue procurar por ela.
     */
    public function scopeSemEscopoDeTenant(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}

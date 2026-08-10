<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Injeta `where tenant_id = ?` em toda leitura dos models que usam a trait.
 *
 * A defesa contra IDOR aqui é estrutural, não uma checagem que alguém precisa
 * lembrar de escrever: um controller que esqueça de filtrar por empresa
 * continua filtrado, porque o filtro está no builder e não no controller. É a
 * diferença entre uma regra que se aplica por padrão e uma que se aplica quando
 * o programador acerta.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = self::tenantAtual();

        if ($tenantId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }

    /**
     * A empresa do request atual, ou null quando não há uma para aplicar.
     *
     * Null tem DOIS significados, e ambos resultam em query sem filtro:
     *
     *  1. Ninguém autenticado — seeder, migration, comando de console, job.
     *     Sem request não existe empresa corrente, e filtrar por uma empresa
     *     arbitrária faria o seeder gravar no lugar errado em silêncio.
     *  2. Admin de plataforma (`users.tenant_id` nulo) — quem opera o SaaS e
     *     precisa enxergar todas as empresas para dar suporte.
     *
     * O caso 1 é o que exige atenção de quem for escrever job ou comando novo:
     * código sem usuário logado NÃO é escopado e enxerga a base inteira. Onde
     * isso importar, resolva a empresa explicitamente em vez de confiar no
     * escopo — ver o cache de CostSetting::current(), que faz exatamente isso.
     */
    public static function tenantAtual(): ?int
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        return $user->tenant_id === null ? null : (int) $user->tenant_id;
    }
}

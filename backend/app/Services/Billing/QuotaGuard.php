<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\TenantQuota;
use App\Models\Client;
use App\Models\Material;
use App\Models\Quote;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quanto a empresa já consumiu e quanto o plano dela permite.
 *
 * Uma classe só, e não uma checagem espalhada pelos controllers, porque a
 * contagem tem sutilezas que precisam valer igual em todo lugar: o que conta
 * como "usado", se o apagado logicamente conta, e qual janela de tempo se
 * aplica. Duas implementações divergentes dessas regras produziriam um sistema
 * onde o aviso do painel e o bloqueio do middleware discordam — e o usuário vê
 * "12 de 20" na tela enquanto o servidor recusa o décimo terceiro.
 */
class QuotaGuard
{
    /**
     * O teto efetivo. Null = ilimitado.
     *
     * Cortesia manual vence o plano; na ausência dela, vale o padrão do enum.
     * Ver a migration de planos para o motivo de a coluna ser nullable.
     *
     * `planoVigente()` e não `plan_type`: entre o fim do período de teste e a
     * passagem do cron da madrugada, a coluna ainda diz "Pro" e a verdade já é
     * "gratuito". Ler a coluna daria cotas ilimitadas de graça por até um dia a
     * cada empresa que testou o produto.
     */
    public function limite(Tenant $tenant, TenantQuota $quota): ?int
    {
        $cortesia = $tenant->getAttribute($quota->colunaDeCortesia());

        if ($cortesia !== null) {
            return (int) $cortesia;
        }

        return $quota->padraoDoPlano($tenant->planoVigente());
    }

    /**
     * O consumo atual.
     *
     * Sempre com `withoutGlobalScope` e `where` explícito. O TenantScope faria o
     * trabalho durante uma requisição autenticada, mas esta classe também roda
     * em comando de console e em rota de plataforma, onde não há usuário logado
     * — e lá o escopo não filtra NADA. Contar a base inteira e comparar com a
     * cota de uma empresa liberaria ou barraria todo mundo de uma vez.
     */
    public function consumo(Tenant $tenant, TenantQuota $quota): int
    {
        return match ($quota) {
            TenantQuota::Materials => $this->contar(Material::query(), $tenant),

            /*
             * Orçamentos: mês corrente e COM os excluídos logicamente.
             *
             * withTrashed porque a cota é sobre criar, não sobre manter. Sem
             * isso, apagar e recriar seria um jeito trivial de imprimir
             * orçamentos infinitos — e a linha apagada continua ocupando o
             * banco que a cota existe para conter.
             */
            TenantQuota::Quotes => $this->contar(
                Quote::query()->withTrashed()->where('created_at', '>=', now()->startOfMonth()),
                $tenant,
            ),

            /*
             * Clientes: inclusive os inativos. ClientController::destroy
             * desativa em vez de apagar (o caixa precisa da contraparte), então
             * ignorar inativos deixaria a cota sem efeito depois do primeiro
             * ciclo de limpeza.
             */
            TenantQuota::Clients => $this->contar(Client::query(), $tenant),
        };
    }

    /** Já atingiu o teto? Ilimitado nunca atinge. */
    public function atingiu(Tenant $tenant, TenantQuota $quota): bool
    {
        $limite = $this->limite($tenant, $quota);

        if ($limite === null) {
            return false;
        }

        return $this->consumo($tenant, $quota) >= $limite;
    }

    /**
     * Panorama das três cotas — alimenta o painel do assinante e o de plataforma.
     *
     * @return array<string, array{limite: ?int, usado: int, restante: ?int, mensal: bool, rotulo: string}>
     */
    public function resumo(Tenant $tenant): array
    {
        $resumo = [];

        foreach (TenantQuota::cases() as $quota) {
            $limite = $this->limite($tenant, $quota);
            $usado = $this->consumo($tenant, $quota);

            $resumo[$quota->value] = [
                'rotulo' => $quota->label(),
                'limite' => $limite,
                'usado' => $usado,
                'restante' => $limite === null ? null : max($limite - $usado, 0),
                'mensal' => $quota->eMensal(),
            ];
        }

        return $resumo;
    }

    /** @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query */
    private function contar(Builder $query, Tenant $tenant): int
    {
        return $query
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->count();
    }
}

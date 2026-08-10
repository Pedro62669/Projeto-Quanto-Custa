<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Os recursos que o plano limita.
 *
 * Vocabulário fechado em vez de string solta: o middleware, o painel e as
 * mensagens de erro precisam falar do mesmo recurso, e um typo em "materials"
 * viraria uma cota que nunca dispara — a falha silenciosa mais cara possível
 * num módulo cuja função é cobrar.
 */
enum TenantQuota: string
{
    case Materials = 'materials';
    case Quotes = 'quotes';
    case Clients = 'clients';

    public function label(): string
    {
        return match ($this) {
            self::Materials => 'matérias-primas',
            self::Quotes => 'orçamentos por mês',
            self::Clients => 'clientes',
        };
    }

    /** Coluna de cortesia em `tenants`. Null nela = segue o plano. */
    public function colunaDeCortesia(): string
    {
        return match ($this) {
            self::Materials => 'max_materials',
            self::Quotes => 'max_quotes',
            self::Clients => 'max_clients',
        };
    }

    /** O teto padrão do plano. Null = ilimitado. */
    public function padraoDoPlano(PlanType $plano): ?int
    {
        return match ($this) {
            self::Materials => $plano->maxMaterials(),
            self::Quotes => $plano->maxQuotesPerMonth(),
            self::Clients => $plano->maxClients(),
        };
    }

    /**
     * A contagem é de estoque (absoluta) ou de fluxo (mensal)?
     *
     * Só orçamento é fluxo. Ver PlanType::maxQuotesPerMonth() para o porquê.
     */
    public function eMensal(): bool
    {
        return $this === self::Quotes;
    }

    /** O que o usuário lê quando bate no teto. */
    public function mensagemDeLimite(int $limite): string
    {
        return match ($this) {
            self::Materials => "Seu plano permite {$limite} matérias-primas cadastradas. "
                .'Faça upgrade para cadastrar mais, ou desative um material que não usa.',
            self::Quotes => "Seu plano permite {$limite} orçamentos por mês. "
                .'A contagem zera no dia 1º; faça upgrade para não esperar.',
            self::Clients => "Seu plano permite {$limite} clientes cadastrados. "
                .'Faça upgrade para cadastrar mais.',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Plano contratado pela empresa assinante.
 *
 * Os limites moram AQUI, no enum, e não numa tabela `plans`. A razão é o que
 * acontece quando o plano muda: se cada empresa carregasse uma cópia dos
 * números, subir o teto do Pro exigiria um UPDATE em massa, e quem entrasse
 * amanhã pegaria valores diferentes de quem entrou ontem — sem que nada no
 * código denunciasse a divergência.
 *
 * As colunas `max_*` em `tenants` continuam existindo, mas são NULLABLE e
 * significam "cortesia": só quem recebeu uma exceção manual tem número próprio.
 * Todo o resto segue o plano e acompanha qualquer mudança dele de graça.
 * Ver Tenant::limiteDe().
 */
enum PlanType: string
{
    /** Grátis. Dimensionado para conhecer o sistema, não para operar nele. */
    case Free = 'free';

    /** O plano da cartonagem que trabalha sozinha ou com um ajudante. */
    case Basic = 'basic';

    /** Sem tetos. É o argumento de venda do plano — não invente um limite. */
    case Pro = 'pro';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Gratuito',
            self::Basic => 'Básico',
            self::Pro => 'Profissional',
        };
    }

    /** Mensalidade em reais. Zero no gratuito. */
    public function monthlyPrice(): float
    {
        return match ($this) {
            self::Free => 0.0,
            self::Basic => 79.90,
            self::Pro => 149.90,
        };
    }

    /**
     * Teto de matérias-primas cadastradas. Null = ilimitado.
     *
     * Contagem ABSOLUTA: material é cadastro, não movimento. Quem tem 40 papéis
     * no estoque tem 40 o ano inteiro.
     */
    public function maxMaterials(): ?int
    {
        return match ($this) {
            self::Free => 10,
            self::Basic => 60,
            self::Pro => null,
        };
    }

    /**
     * Teto de clientes cadastrados. Null = ilimitado. Também absoluto.
     */
    public function maxClients(): ?int
    {
        return match ($this) {
            self::Free => 15,
            self::Basic => 300,
            self::Pro => null,
        };
    }

    /**
     * Teto de orçamentos NO MÊS. Null = ilimitado.
     *
     * Por mês, e não no total — a diferença é o que separa uma cota de um
     * tijolo. Orçamento não é só rascunho: é o lastro da venda aprovada, com a
     * transação do caixa apontando para ele e a ficha técnica saindo dele. Um
     * teto acumulado significaria que a empresa, ao completar um ano de uso,
     * simplesmente para de conseguir vender — e o caminho para destravar seria
     * apagar o próprio histórico financeiro.
     *
     * A janela mensal ainda limita o crescimento da base (que é o custo real de
     * banco que a cota existe para conter) sem nunca bloquear quem já vendeu.
     */
    public function maxQuotesPerMonth(): ?int
    {
        return match ($this) {
            self::Free => 20,
            self::Basic => 250,
            self::Pro => null,
        };
    }

    /** É plano pago? Só estes passam pelo gateway. */
    public function isPaid(): bool
    {
        return $this !== self::Free;
    }
}

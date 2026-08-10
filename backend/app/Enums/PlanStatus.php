<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Situação da assinatura perante o financeiro da plataforma.
 *
 * Separado de `tenants.is_active` de propósito. `is_active` é uma decisão
 * ADMINISTRATIVA (suspensão por abuso, encerramento a pedido); o status do plano
 * é uma consequência do PAGAMENTO. Misturar os dois faria uma fatura em atraso
 * ser indistinguível de um banimento — e a reativação automática ao pagar
 * reabriria a conta de quem foi suspenso por outro motivo.
 */
enum PlanStatus: string
{
    /** Em período de teste, ainda sem cobrança. */
    case Trialing = 'trialing';

    /** Em dia. */
    case Active = 'active';

    /**
     * Fatura vencida e não paga.
     *
     * Não bloqueia de imediato, e nenhum método deste enum decide isso: quem
     * corta o acesso é `tenants.subscription_ends_at`, a data do período já
     * pago. Cortar na primeira falha de cartão puniria quem trocou de banco
     * tanto quanto quem desistiu de pagar. Ver Tenant::acessoLiberado().
     */
    case PastDue = 'past_due';

    /** Cancelada. O acesso segue até `subscription_ends_at`, se houver. */
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Em teste',
            self::Active => 'Ativa',
            self::PastDue => 'Pagamento pendente',
            self::Canceled => 'Cancelada',
        };
    }
}

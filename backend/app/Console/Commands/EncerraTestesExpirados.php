<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Fecha os períodos de teste vencidos, rebaixando para o gratuito.
 *
 * REBAIXA, não bloqueia — a distinção é a decisão de produto por trás deste
 * comando. Quem passou três dias avaliando e não assinou não é inadimplente: é
 * alguém que ainda não se convenceu. Cortar o acesso transformaria uma avaliação
 * morna em uma reclamação, e os dados que a pessoa cadastrou (materiais, custos,
 * orçamentos de teste) continuam sendo dela. Ela volta às cotas do gratuito e
 * segue usando; o upgrade acontece quando ela esbarrar no limite fazendo algo
 * útil, que é a hora em que o plano pago faz sentido para ela.
 *
 * Por isso `subscription_ends_at` NÃO é tocado aqui: preenchê-lo jogaria a
 * empresa no EnsureSubscriptionIsActive e viraria bloqueio.
 *
 * O comando existe apesar de Tenant::planoVigente() já saber a resposta. Não é
 * duplicação: o método decide a cota de um request, mas os números do painel de
 * plataforma são agregados em SQL (`GROUP BY plan_type`) e não conseguem chamar
 * método PHP. Sem materializar, o painel contaria como Pro toda empresa cujo
 * teste venceu.
 */
class EncerraTestesExpirados extends Command
{
    protected $signature = 'billing:encerrar-testes
                            {--dry-run : Lista o que seria rebaixado, sem gravar}';

    protected $description = 'Rebaixa para o plano gratuito as empresas com período de teste vencido';

    public function handle(): int
    {
        $simulacao = (bool) $this->option('dry-run');

        $expiradas = Tenant::query()
            ->where('plan_status', PlanStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())

            /*
             * Quem já está no gratuito não precisa ser rebaixado de novo. Sem
             * este filtro o comando reescreveria as mesmas linhas todo dia,
             * sujando `updated_at` e escondendo, num histórico de alterações,
             * qual foi a mudança que realmente aconteceu.
             */
            ->whereNot('plan_type', PlanType::Free)

            ->get();

        if ($expiradas->isEmpty()) {
            $this->info('Nenhum período de teste vencido.');

            return self::SUCCESS;
        }

        foreach ($expiradas as $tenant) {
            $this->line(sprintf(
                '%s#%d %s — teste venceu em %s (era %s)',
                $simulacao ? '[simulação] ' : '',
                $tenant->id,
                $tenant->name,
                $tenant->trial_ends_at->format('d/m/Y'),
                $tenant->plan_type->label(),
            ));

            if ($simulacao) {
                continue;
            }

            /*
             * `Active` e não `Canceled`: a conta está em ordem, apenas no plano
             * gratuito. Marcá-la como cancelada misturaria "nunca assinou" com
             * "assinou e desistiu" — e são coisas diferentes para quem lê o
             * painel decidindo onde investir.
             */
            $tenant->forceFill([
                'plan_type' => PlanType::Free,
                'plan_status' => PlanStatus::Active,
            ])->save();
        }

        $this->info(($simulacao ? 'Simulação: ' : '').$expiradas->count().' empresa(s) rebaixada(s) para o gratuito.');

        return self::SUCCESS;
    }
}

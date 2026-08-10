<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plano e cotas da empresa assinante.
 *
 * Duas decisões de modelagem que valem o comentário:
 *
 * 1. `plan_type` e `plan_status` são STRING com cast de enum no PHP, não enum de
 *    banco. É a convenção do projeto (ver box_model, quote_status) e existe por
 *    um motivo prático: enum nativo obriga um ALTER TABLE a cada valor novo, e o
 *    SQLite — que roda em desenvolvimento aqui — sequer tem o tipo.
 *
 * 2. Os `max_*` são NULLABLE, e null NÃO significa ilimitado: significa "segue o
 *    plano". Os números vivem no enum PlanType, e a coluna só é preenchida
 *    quando alguém concede uma cortesia manual — o caso que o painel de
 *    plataforma precisa atender. Assim, subir o teto do Básico amanhã alcança
 *    todo mundo que nunca recebeu exceção, sem UPDATE em massa e sem que duas
 *    empresas do mesmo plano fiquem com números diferentes em silêncio.
 *    Ver Tenant::limiteDe().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan_type', 10)->default('free')->after('is_active')
                ->comment('free | basic | pro — limites em App\Enums\PlanType');

            $table->string('plan_status', 12)->default('trialing')->after('plan_type')
                ->comment('trialing | active | past_due | canceled');

            /*
             * Cortesias. Null = usa o padrão do plano; número = exceção manual
             * concedida pelo admin de plataforma, que sobrevive à troca de plano
             * de propósito (foi concedida à empresa, não ao pacote).
             */
            $table->unsignedInteger('max_materials')->nullable()->after('plan_status');
            $table->unsignedInteger('max_quotes')->nullable()->after('max_materials')
                ->comment('Teto MENSAL de orçamentos — ver PlanType::maxQuotesPerMonth()');
            $table->unsignedInteger('max_clients')->nullable()->after('max_quotes');

            $table->timestamp('trial_ends_at')->nullable()->after('max_clients');

            /*
             * Fim do acesso pago. É a data EFETIVA, consultada a cada requisição
             * — por isso mora aqui e não na tabela de assinaturas: checar cota e
             * validade não pode custar um join.
             */
            $table->timestamp('subscription_ends_at')->nullable()->after('trial_ends_at');

            // O painel de plataforma agrupa por plano e por situação.
            $table->index(['plan_type', 'plan_status']);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['plan_type', 'plan_status']);
            $table->dropColumn([
                'plan_type', 'plan_status',
                'max_materials', 'max_quotes', 'max_clients',
                'trial_ends_at', 'subscription_ends_at',
            ]);
        });
    }
};

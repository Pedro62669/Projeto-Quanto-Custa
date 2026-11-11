<?php

use App\Enums\PlanType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O plano gratuito e o período de teste saem do produto.
 *
 * Decisão comercial: nada no sistema é grátis. Sobram Básico e Profissional, os
 * dois pagos, e a conta recém-criada passa a NÃO TER PLANO — estado que antes
 * não existia, porque todo mundo caía no gratuito.
 *
 * ## Por que NULL e não um plano "pendente"
 *
 * A tentação é criar `PlanType::Pending` e manter a coluna obrigatória. Seria
 * mentira de modelagem: `plan_type` responde "qual plano esta empresa contratou",
 * e a resposta para quem nunca contratou é nenhum, não "o pendente". Com um
 * valor sentinela, todo `match` sobre o enum passaria a ter um braço que não
 * descreve plano nenhum — e `monthlyPrice()` teria de inventar um preço.
 *
 * NULL também faz o tipo trabalhar a favor: `?PlanType` obriga cada consumidor a
 * decidir o que faz sem plano, e o analisador estático aponta quem esqueceu.
 *
 * ## O que acontece com os dados que já existem
 *
 * Três situações, e elas não podem ser tratadas igual:
 *
 *  • Em teste (`trialing`) — nunca pagou. Vira sem plano.
 *  • No gratuito sem histórico — nunca pagou. Vira sem plano.
 *  • No gratuito com assinatura cancelada — PAGOU um dia. O plano volta da
 *    tabela `subscriptions`, que guarda o que foi contratado de verdade.
 *    Rebaixar essa empresa a "nunca teve plano" apagaria a única prova de que
 *    ela foi cliente, e é dela que sai o relatório de churn.
 *
 * Nenhuma delas ganha acesso de escrita por causa desta migração: quem manda
 * nisso é `subscription_ends_at`, que não é tocado aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Nullable ANTES de gravar NULL, na ordem óbvia mas fácil de inverter:
         * a coluna ainda é NOT NULL com default 'free' neste ponto.
         */
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan_type', 10)->nullable()->default(null)->change();
            $table->string('plan_status', 12)->nullable()->default(null)->change();
        });

        /*
         * A conversão dos dados roda em PHP, linha a linha, e não em SQL.
         *
         * A primeira versão usava `DB::raw` com subquery e `date_add(...)`, e
         * quebrou no primeiro `migrate`: desenvolvimento roda em SQLite,
         * produção e a suíte em MariaDB, e a aritmética de datas tem sintaxe
         * diferente em cada um. Uma migração que só funciona num dos bancos do
         * projeto é uma migração que ninguém consegue testar antes de aplicar.
         *
         * `tenants` é a tabela mais rasa do sistema — uma linha por empresa
         * assinante — então o laço custa menos que a portabilidade que ele
         * compra.
         */
        DB::table('tenants')->orderBy('id')->chunkById(200, function ($empresas): void {
            foreach ($empresas as $empresa) {
                $mudanca = $this->converte($empresa);

                // Array vazio = nada a converter. `update([])` monta um
                // "update tenants set where id = ?" e explode no driver.
                if ($mudanca === []) {
                    continue;
                }

                DB::table('tenants')->where('id', $empresa->id)->update($mudanca);
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });
    }

    /**
     * O novo estado de uma empresa, a partir do antigo.
     *
     * @return array<string, mixed>
     */
    private function converte(object $empresa): array
    {
        $nuncaPagou = $empresa->plan_type === 'free' || $empresa->plan_status === 'trialing';

        if ($nuncaPagou) {
            /*
             * Estava no gratuito ou em teste. O plano contratado volta da tabela
             * de assinaturas, quando houver: quem PAGOU um dia não pode virar
             * "nunca teve plano", porque é dessa linha que sai o relatório de
             * churn. Quem nunca pagou fica sem plano e sem situação.
             */
            $ultima = DB::table('subscriptions')
                ->where('tenant_id', $empresa->id)
                ->where('plan_type', '<>', 'free')
                ->orderByDesc('started_at')
                ->first();

            return $ultima === null
                ? ['plan_type' => null, 'plan_status' => null]
                : ['plan_type' => $ultima->plan_type];
        }

        /*
         * Assinante ativo sem data de validade: ninguém perde acesso por causa
         * desta migração.
         *
         * `subscription_ends_at` passou a significar "pode escrever até esta
         * data", e NULL passou a significar "nunca assinou". A inversão é o
         * ponto da mudança — mas alcançaria, sem querer, o assinante ATIVO com a
         * data em branco.
         *
         * Em produção o webhook sempre carimba a data ao ativar, então o estado
         * não deveria existir. "Não deveria" não é garantia: ativação manual
         * pelo painel de plataforma, seed antigo ou reconciliação incompleta
         * produzem exatamente isso — e o efeito seria um cliente pagante que, no
         * dia do deploy, para de conseguir salvar um orçamento sem nenhum aviso.
         *
         * O período do gateway é a primeira escolha; sem ele, trinta dias de
         * carência. Erra para o lado de dar acesso a quem talvez não devesse,
         * que é o erro barato — o outro é tirar o produto de quem pagou.
         */
        if ($empresa->plan_status === 'active' && $empresa->subscription_ends_at === null) {
            $ativa = DB::table('subscriptions')
                ->where('tenant_id', $empresa->id)
                ->where('status', 'active')
                ->orderByDesc('started_at')
                ->first();

            return [
                'subscription_ends_at' => $ativa?->current_period_ends_at
                    ?? now()->addDays(30)->toDateTimeString(),
            ];
        }

        return [];
    }

    /**
     * A volta recria as colunas e devolve o gratuito a quem ficou sem plano.
     *
     * Não restaura data de teste nenhuma: ela foi apagada, e inventar um
     * `trial_ends_at` no rollback daria período de teste novo a contas antigas.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('started_at');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('max_clients');
        });

        DB::table('tenants')->whereNull('plan_type')->update([
            'plan_type' => 'free',
            'plan_status' => 'canceled',
        ]);

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan_type', 10)->default('free')->nullable(false)->change();
            $table->string('plan_status', 12)->default('trialing')->nullable(false)->change();
        });
    }
};

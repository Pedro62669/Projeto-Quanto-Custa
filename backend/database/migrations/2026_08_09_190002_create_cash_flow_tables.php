<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Livro caixa: cabeçalho de transação e suas parcelas.
 *
 * Duas tabelas em vez de uma porque respondem a perguntas diferentes. A
 * transação é o FATO ("vendi R$ 3.000 para a Ana em 12/08"); a parcela é a
 * PROMESSA ("R$ 1.000 vencem em 12/09"). Guardar só o fato impediria o fluxo
 * projetado; guardar só as parcelas perderia o vínculo com o orçamento e com o
 * cliente, e a soma de três linhas soltas não conta a mesma história.
 *
 * É a separação que sustenta as duas colunas do painel — realizado (parcelas
 * quitadas) e projetado (parcelas que vencem) — a partir da mesma base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            /*
             * Contraparte: cliente numa entrada, fornecedor numa saída. Os dois
             * nullable porque nem todo lançamento tem uma — despesa fixa e
             * aporte de sócio não têm contraparte cadastrada.
             *
             * nullOnDelete e não cascade: apagar um cliente não pode apagar o
             * histórico financeiro dele. O dinheiro entrou; a linha do caixa
             * precisa continuar batendo com o extrato bancário.
             */
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Orçamento de origem. nullOnDelete pelo mesmo motivo, e é o que
             * torna a exclusão lógica de um orçamento inofensiva ao caixa.
             */
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 10)->comment('entry | exit');
            $table->string('category', 30)
                ->comment('quote_sale | product_sale | material_purchase | fixed_cost | other');

            $table->decimal('amount', 12, 2)->comment('Valor total, sempre positivo — o sentido está em type');
            $table->string('description');
            $table->date('transaction_date')->comment('Data do fato gerador, não do vencimento');

            $table->timestamps();

            // O painel filtra por empresa + período + sentido; esta é a ordem
            // que serve às três perguntas com um índice só.
            $table->index(['tenant_id', 'type', 'transaction_date']);
            $table->index(['tenant_id', 'category']);
            $table->index(['tenant_id', 'quote_id']);
        });

        Schema::create('installments', function (Blueprint $table) {
            $table->id();

            /**
             * `tenant_id` denormalizado, e isso é deliberado.
             *
             * A empresa já é derivável pela transação, mas o TenantScope filtra
             * a tabela que está sendo CONSULTADA — e o painel consulta parcelas
             * diretamente ("o que vence este mês"). Sem a coluna, essa query
             * atravessaria empresas, que é exatamente o IDOR que a Fase 1
             * fechou. Uma coluna redundante é barata; um vazamento não.
             */
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('installment_number')->comment('1, 2, 3...');
            $table->unsignedSmallInteger('total_installments')->comment('o denominador de 1/3');

            $table->decimal('amount', 12, 2);
            $table->date('due_date');

            /**
             * Data em que o dinheiro trocou de mãos de fato.
             *
             * Separada do vencimento porque as duas quase nunca coincidem, e é a
             * diferença entre elas que separa o caixa REALIZADO do PROJETADO —
             * as duas colunas do painel.
             */
            $table->date('payment_date')->nullable();

            $table->string('status', 12)->default('pending')->comment('pending | completed');

            $table->timestamps();

            // Não existe parcela 2 duas vezes na mesma transação.
            $table->unique(['transaction_id', 'installment_number']);

            // As duas varreduras do painel: o que vence e o que foi quitado.
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'status', 'payment_date']);
        });

        /*
         * Vínculo do orçamento com o cliente cadastrado.
         *
         * Os campos de texto (`client_name`, `client_email`, `client_document`)
         * FICAM. Eles são snapshot do que foi combinado, na mesma filosofia do
         * `pricing_snapshot`: corrigir o cadastro do cliente amanhã não pode
         * reescrever a proposta que ele assinou ontem.
         */
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();

            $table->index(['tenant_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'client_id']);
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
        });

        Schema::dropIfExists('installments');
        Schema::dropIfExists('transactions');
    }
};

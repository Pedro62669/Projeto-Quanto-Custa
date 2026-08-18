<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produtos deixa de ser ilha.
 *
 * A tabela guardava custo, preço de venda, estoque e margem, e tinha ZERO
 * relações — nem com orçamento, nem com cliente, nem com o caixa. O comentário
 * do modelo dizia "produto pronto para revenda casada com a embalagem" e nada
 * casava com nada.
 *
 * O sinal de que a ligação era prevista e nunca construída está no enum
 * `TransactionCategory::ProductSale`, que existe desde a Fase 4 e nunca foi
 * usado por linha nenhuma de código.
 *
 * Duas colunas resolvem as duas pontas:
 *
 * - `kind` distingue a CAIXA PRONTA, que nasce de um orçamento aprovado e traz
 *   o preço que o motor calculou, da MERCADORIA avulsa, comprada pronta e com
 *   preço digitado. A interface precisa saber qual é qual porque uma se cria
 *   pelo botão do orçamento e a outra pelo formulário.
 *
 * - `quote_id` guarda de onde a caixa veio. É o que permite abrir a proposta
 *   original a partir do catálogo e responder "esse preço saiu de quando?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            /*
             * Default `merchandise` para as linhas que já existem.
             *
             * Quem cadastrou produto antes desta migração o fez pelo formulário,
             * digitando o preço — que é exatamente a definição de mercadoria.
             * Marcar tudo como caixa pronta prometeria um orçamento de origem
             * que não existe, e a tela abriria um link para lugar nenhum.
             */
            $table->string('kind', 12)
                ->default('merchandise')
                ->after('tenant_id')
                ->comment('box | merchandise — ver App\Enums\ProductKind');

            /*
             * nullOnDelete e não cascade: apagar o orçamento não pode apagar o
             * produto do catálogo. A caixa continua existindo e continua sendo
             * vendida; o que se perde é o atalho para a proposta que a originou.
             *
             * `quotes` usa SoftDeletes, então na prática o gatilho quase não
             * dispara — é rede para o caminho que não passa pelo controller.
             */
            $table->foreignId('quote_id')->nullable()->after('kind')
                ->constrained()->nullOnDelete();

            // "As caixas prontas do catálogo" é a consulta que a tela faz ao
            // separar as duas abas.
            $table->index(['tenant_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'kind']);
            $table->dropConstrainedForeignId('quote_id');
            $table->dropColumn('kind');
        });
    }
};

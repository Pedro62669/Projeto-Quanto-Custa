<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A venda de produto passa a saber O QUE vendeu.
 *
 * `transactions` já apontava para cliente, fornecedor e orçamento — as três
 * contrapartes que o sistema conhecia. Produto ficava de fora porque não havia
 * venda de produto: a categoria `ProductSale` existia no enum desde a Fase 4 e
 * nunca fora usada por linha nenhuma de código.
 *
 * Sem esta coluna, a baixa de estoque e o lançamento no caixa seriam dois fatos
 * soltos: daria para somar quanto entrou de revenda no mês, e não para
 * responder de quais produtos — que é a pergunta que decide o que recomprar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            /*
             * nullOnDelete como as outras contrapartes: desativar ou apagar um
             * produto não pode apagar a venda dele. O dinheiro entrou, e o
             * livro-caixa registra o que aconteceu — não o que ainda existe no
             * cadastro.
             */
            $table->foreignId('product_id')->nullable()->after('quote_id')
                ->constrained()->nullOnDelete();

            // "Quanto este produto já vendeu" — a consulta da ficha do produto.
            $table->index(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'product_id']);
            $table->dropConstrainedForeignId('product_id');
        });
    }
};

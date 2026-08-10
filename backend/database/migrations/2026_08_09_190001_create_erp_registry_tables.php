<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastros gerenciais de apoio: clientes, fornecedores e produtos de revenda.
 *
 * As três numa migration só porque são a mesma decisão: tabelas de cadastro,
 * sem regra de negócio, escopadas por empresa. Separá-las em três arquivos
 * daria três migrations que sempre serão executadas juntas.
 *
 * `state` com índice em clients existe para uma pergunta específica do roadmap:
 * o mapa geográfico de onde os clientes estão. Sem o índice, o relatório varre
 * a tabela inteira a cada abertura do painel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            /**
             * CPF ou CNPJ, somente dígitos — mesma decisão de `tenants.document`.
             *
             * Único POR EMPRESA, e não global: o mesmo cliente pode comprar de
             * duas cartonagens diferentes, e um único global impediria a
             * segunda de cadastrá-lo.
             */
            $table->string('cpf_cnpj', 14)->nullable();

            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();

            $table->char('state', 2)->nullable()->comment('UF — alimenta o relatório geográfico');
            $table->string('city')->nullable();
            $table->string('address')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'cpf_cnpj']);
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'state']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->char('state', 2)->nullable();
            $table->string('city')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('sku', 60)->nullable();

            // 2 casas: são preços de nota, não custos unitários de fração de
            // centavo como os do motor de precificação.
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('sale_price', 12, 2)->default(0);

            /**
             * Saldo simples, sem razão de movimentação.
             *
             * Assinado de propósito: estoque negativo é um dado verdadeiro
             * (vendeu o que não tinha) e esconder isso com unsigned faria o
             * lançamento falhar em vez de mostrar o problema. Um livro de
             * movimentações é trabalho de outra fase.
             */
            $table->integer('stock_quantity')->default(0);

            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('clients');
    }
};

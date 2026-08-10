<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Despesas fixas mensais da empresa — o numerador da hora-empresa.
 *
 * Linha a linha, e não uma coluna "total_custos_fixos": o usuário precisa
 * enxergar de onde vem o número. Quem descobre que a hora custa R$ 47 e não
 * consegue abrir a conta não confia nela e volta a chutar preço — que é
 * exatamente o hábito que este sistema existe para substituir. Ver o total
 * quebrado em aluguel, contador, energia e pró-labore também mostra onde
 * cortar.
 *
 * Por que aqui e não em `cost_settings`: aquela tabela guarda TAXAS já
 * calculadas (R$/hora de máquina, R$/hora de mão de obra). Esta guarda a
 * despesa bruta que o boleto cobra. A hora-empresa é justamente a ponte entre
 * as duas — transforma despesa mensal em taxa horária.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_costs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name')->comment('Aluguel, energia, contador, pró-labore, MEI, marketing...');

            $table->decimal('monthly_amount', 12, 2);

            /**
             * Desativar em vez de apagar.
             *
             * O usuário simula: "e se eu cortar o marketing?" Zerar a linha
             * perderia o valor e ele teria que redigitar para voltar atrás. O
             * flag deixa a simulação ser reversível com um clique — e é a mesma
             * mecânica que o painel de custos fixos da Fase 2 pede.
             */
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // A soma da hora-empresa filtra por empresa e por ativo.
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_costs');
    }
};

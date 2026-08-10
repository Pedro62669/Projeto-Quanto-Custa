<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventário de máquinas, para o cálculo de depreciação.
 *
 * Depreciação aqui não é a da contabilidade fiscal: é a reserva de reposição.
 * A vinco que custou R$ 12.000 e dura cinco anos precisa devolver R$ 200 por
 * mês embutidos no preço, senão a empresa fatura cinco anos e descobre no fim
 * que não tem como comprar a máquina de novo. É o custo mais fácil de esquecer
 * porque ele não chega como boleto — chega como sucata.
 *
 * `useful_life_months` em MESES, e não em anos: o motor de custo raciocina em
 * mês (a hora-empresa é mensal), e guardar anos obrigaria a converter em todo
 * lugar que lê. Meses também descrevem melhor a realidade de equipamento usado,
 * onde "mais uns dezoito meses" é uma estimativa comum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            /**
             * Valor de aquisição, em 2 casas: é o que está na nota fiscal.
             *
             * Diferente dos custos unitários do motor de preço, que usam 4 casas
             * porque uma embalagem pode custar R$ 0,0842. Uma máquina não custa
             * fração de centavo.
             */
            $table->decimal('purchase_value', 12, 2);

            /**
             * Vida útil em meses. unsignedSmallInteger sustenta até 65.535
             * meses (5.461 anos) — folga absurda para o domínio, e ainda assim
             * o tipo mais barato que impede negativo pelo próprio banco.
             *
             * O zero é o que realmente machuca aqui: é divisor da depreciação
             * mensal. Barrado na validação da Request e no acessor do model,
             * porque um seeder ou import não passa pela Request.
             */
            $table->unsignedSmallInteger('useful_life_months');

            $table->timestamps();

            // Toda leitura é escopada por empresa — ver TenantScope.
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};

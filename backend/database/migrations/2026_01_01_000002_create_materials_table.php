<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matérias-primas cadastradas pelo admin (papelão, papel, tecido, ...).
 *
 * Ponto central de modelagem: materiais são cotados em unidades diferentes.
 * Papelão costuma ser vendido por m², tecido e papel por kg. Em vez de criar
 * duas tabelas, guardamos a unidade de compra em `cost_unit` e — quando a
 * unidade é kg — a gramatura em `grammage_kg_per_m2`, que é o fator de
 * conversão para levar tudo ao denominador comum do cálculo: R$/m².
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('type', 30)->comment('cardboard | paper | fabric | plastic | other');
            $table->text('description')->nullable();

            // --- Custo -------------------------------------------------
            $table->string('cost_unit', 5)->comment('m2 | kg');
            $table->decimal('cost_per_unit', 12, 4)->comment('Preço de compra na unidade de cost_unit');

            /**
             * Obrigatório quando cost_unit = 'kg'. Ex.: papelão 300g/m² => 0.300.
             * Validado na Request, não no banco, para produzir mensagem de erro legível.
             */
            $table->decimal('grammage_kg_per_m2', 8, 4)->nullable();

            // --- Parâmetros de produção --------------------------------
            $table->decimal('default_waste_percent', 5, 2)
                ->default(10.00)
                ->comment('Desperdício padrão (%) — sobreposto por orçamento se necessário');

            $table->decimal('thickness_mm', 6, 2)->nullable()
                ->comment('Espessura: usada na renderização 3D e em folgas de encaixe');

            // --- Apresentação (consumido pelo renderizador 3D) ---------
            $table->string('color_hex', 7)->default('#C8A06A');
            $table->string('texture_url')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};

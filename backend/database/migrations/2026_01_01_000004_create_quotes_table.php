<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de orçamentos.
 *
 * Regra de ouro desta tabela: um orçamento é um DOCUMENTO, não uma consulta.
 * Se amanhã o admin reajustar o papelão em 20%, o orçamento emitido hoje deve
 * continuar mostrando exatamente os números que o cliente recebeu. Por isso:
 *
 *   - os resultados são colunas materializadas (não recalculadas na leitura);
 *   - `pricing_snapshot` guarda em JSON os parâmetros de entrada usados
 *     (custo do material, tarifas vigentes, versão do motor de cálculo),
 *     o que torna cada linha auditável e reproduzível;
 *   - as FKs usam RESTRICT/nullOnDelete para não perder o histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();

            // Número legível para o cliente (ORC-2026-000123), gerado no Model.
            $table->string('reference', 30)->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->foreignId('cost_setting_id')->nullable()->constrained()->nullOnDelete();

            // --- Cliente -------------------------------------------------
            $table->string('client_name');
            $table->string('client_email')->nullable();
            $table->string('client_document', 20)->nullable();

            // --- Especificação da embalagem ------------------------------
            // Dimensões internas em MILÍMETROS (inteiro): evita erro de ponto
            // flutuante e é a unidade prática do chão de fábrica.
            $table->unsignedInteger('width_mm');
            $table->unsignedInteger('height_mm');
            $table->unsignedInteger('depth_mm');

            $table->string('box_model', 30)->default('rsc')
                ->comment('rsc | tray | sleeve | pouch');
            $table->unsignedInteger('quantity')->default(1);

            // --- Parâmetros aplicados ------------------------------------
            $table->decimal('waste_percent', 5, 2);
            $table->decimal('production_minutes_per_unit', 8, 2);
            $table->decimal('profit_margin_percent', 5, 2);
            $table->string('pricing_mode', 10)->default('markup')->comment('markup | margin');

            // --- Resultado: área ------------------------------------------
            $table->decimal('area_m2_per_unit', 12, 6)->comment('Área líquida do plano de corte');
            $table->decimal('area_m2_total', 12, 6)->comment('Já com desperdício e quantidade');

            // --- Resultado: custos (por unidade) --------------------------
            $table->decimal('material_cost', 12, 4);
            $table->decimal('labor_cost', 12, 4);
            $table->decimal('machine_cost', 12, 4);
            $table->decimal('energy_cost', 12, 4);
            $table->decimal('overhead_cost', 12, 4);
            $table->decimal('unit_cost', 12, 4)->comment('CMV unitário');

            // --- Resultado: preço ------------------------------------------
            $table->decimal('unit_price', 12, 4);
            $table->decimal('total_cost', 12, 2)->comment('unit_cost * quantity');
            $table->decimal('total_price', 12, 2)->comment('Preço de venda fechado');
            $table->decimal('profit_amount', 12, 2);

            // --- Auditoria --------------------------------------------------
            $table->json('pricing_snapshot')
                ->comment('Entradas + breakdown completo no momento da emissão');

            $table->string('status', 20)->default('draft')
                ->comment('draft | sent | approved | rejected');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
            $table->index('client_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};

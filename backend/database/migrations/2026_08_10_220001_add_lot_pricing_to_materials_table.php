<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custo derivado do LOTE DE COMPRA, com frete rateado.
 *
 * O que estava faltando: hoje o material guarda R$/m² já calculado, e quem paga
 * R$ 400 de entrega numa carga de chapas nunca vê esse dinheiro no preço da
 * caixa. O frete some — na melhor das hipóteses alguém lembra de inflar a
 * margem, na pior ele simplesmente vira prejuízo diluído.
 *
 * Com as colunas abaixo o custo passa a sair de onde ele nasce:
 *
 *   (valor pago pelo lote + frete) ÷ itens do lote ÷ área da folha
 *
 * Todas NULLABLE, e isso é o desenho e não preguiça: material cadastrado com
 * R$/m² direto continua funcionando exatamente como antes. As colunas só
 * assumem quando o conjunto está completo — ver Material::lotUnitCost().
 * Sem essa convivência, a migração exigiria recadastrar o estoque inteiro
 * antes de o sistema voltar a calcular.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            /*
             * Medida da FOLHA comprada, não da peça cortada. É o que converte
             * "custo por folha" em "custo por m²" — sem ela, saber o preço do
             * lote não diz nada sobre o preço da caixa.
             */
            $table->unsignedInteger('sheet_width_mm')->nullable()->after('grammage_kg_per_m2');
            $table->unsignedInteger('sheet_length_mm')->nullable()->after('sheet_width_mm');

            // Quantas folhas (ou peças) vieram na compra.
            $table->unsignedInteger('lot_quantity')->nullable()->after('sheet_length_mm');

            // Valor da nota, sem frete.
            $table->decimal('lot_purchase_cost', 12, 2)->nullable()->after('lot_quantity');

            /*
             * Frete RATEADO para este material.
             *
             * Separado do valor de compra de propósito: a nota do fornecedor e o
             * conhecimento de transporte são dois documentos, e somá-los numa
             * coluna só apagaria a informação de quanto a logística custa. É um
             * número que a empresa precisa enxergar para decidir se compra mais
             * por viagem — e ele desaparece se virar "preço do papelão".
             */
            $table->decimal('lot_freight_cost', 12, 2)->nullable()->after('lot_purchase_cost');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn([
                'sheet_width_mm', 'sheet_length_mm',
                'lot_quantity', 'lot_purchase_cost', 'lot_freight_cost',
            ]);
        });
    }
};

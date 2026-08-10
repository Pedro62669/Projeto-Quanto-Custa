<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materializa as duas linhas novas da lista de materiais no orçamento gravado.
 *
 * Colunas, e não um campo dentro do `pricing_snapshot`: o snapshot existe para
 * explicar POR QUE o preço foi aquele (parâmetros vigentes na data), enquanto
 * estas são componentes do preço em si — precisam ser somáveis em relatório,
 * filtráveis e comparáveis entre orçamentos. Enterrá-las num JSON tornaria
 * "quanto gastei de ímã no trimestre" uma pergunta cara.
 *
 * Default zero: todo orçamento já emitido é de cartonagem dobrada sem ferragem,
 * e zero é literalmente o que ele consumiu — não é um placeholder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            // 6 casas como area_m2_per_unit: a virada do revestimento move a
            // quarta casa em caixas pequenas, e arredondar antes de multiplicar
            // pela tiragem espalharia o erro.
            $table->decimal('wrap_area_m2_per_unit', 12, 6)->default(0)->after('area_m2_total')
                ->comment('Área do papel de revestimento por peça; 0 fora da cartonagem rígida');

            $table->decimal('wrap_cost', 12, 4)->default(0)->after('material_cost')
                ->comment('Custo do revestimento por peça');

            $table->decimal('hardware_cost', 12, 4)->default(0)->after('wrap_cost')
                ->comment('Ímãs, fechos e fitas por peça — cobrados por unidade');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['wrap_area_m2_per_unit', 'wrap_cost', 'hardware_cost']);
        });
    }
};

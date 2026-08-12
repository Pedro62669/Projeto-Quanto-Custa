<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda QUAL papel reveste a caixa, não só quanto ele custou.
 *
 * O orçamento já gravava `wrap_cost` e `wrap_area_m2_per_unit` — o suficiente
 * para o preço, e insuficiente para tudo o mais. Sem o id não se sabe a medida
 * da folha do revestimento, e sem a folha não há plano de corte: a ficha técnica
 * encaixava só a estrutura e ficava calada sobre o papel, que é o material mais
 * caro da caixa rígida (passa de R$ 20/m² onde o cinza fica em R$ 5).
 *
 * `nullOnDelete`, não cascade: material apagado não pode levar junto o orçamento
 * que o usou. O plano de corte deixa de sair para aquele papel — e o preço
 * gravado continua intacto, porque ele nunca dependeu desta coluna.
 *
 * Nula em tudo que já existe, e nula é o valor certo: cartonagem dobrada não
 * tem revestimento, e os orçamentos rígidos antigos foram fechados sem que o
 * sistema soubesse guardar essa informação. O que não se sabe fica em branco em
 * vez de virar um palpite plausível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('wrap_material_id')
                ->nullable()
                ->after('material_id')
                ->constrained('materials')
                ->nullOnDelete()
                ->comment('Papel de revestimento da cartonagem rígida; null fora dela');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropForeign(['wrap_material_id']);
            $table->dropColumn('wrap_material_id');
        });
    }
};

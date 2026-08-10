<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sentido da fibra — o que decide se uma peça pode girar no plano de corte.
 *
 * Sem esta coluna o nesting não teria de onde tirar a permissão de rotação, e
 * ficaria preso a um dos dois erros: proibir sempre (desperdiça chapa em
 * material sem fibra, como tecido) ou permitir sempre (produz tampa que empena
 * dias depois da entrega, quando já não há conserto).
 *
 * Default 'none' porque é o único honesto para o cadastro que já existe: o
 * sistema não sabe a fibra do que foi cadastrado antes de a coluna existir, e
 * assumir fibra em tudo pioraria o aproveitamento de quem não tem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->string('grain_direction', 10)
                ->default('none')
                ->after('thickness_mm')
                ->comment('none | length | width — ver App\Enums\GrainDirection');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('grain_direction');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medidas da tampa informadas pelo usuário.
 *
 * NULL tem significado aqui: quer dizer "tampa automática", derivada da base.
 * Não é ausência de dado — é a escolha de deixar o sistema decidir, e precisa
 * sobreviver à gravação para que reabrir o orçamento reproduza a mesma peça.
 *
 * Decimais em vez de inteiros: a tampa sugerida sai de uma razão sobre a
 * altura (0,35), que raramente cai em número redondo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->decimal('lid_width_mm', 10, 2)->nullable()->after('depth_mm');
            $table->decimal('lid_depth_mm', 10, 2)->nullable()->after('lid_width_mm');
            $table->decimal('lid_height_mm', 10, 2)->nullable()->after('lid_depth_mm');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['lid_width_mm', 'lid_depth_mm', 'lid_height_mm']);
        });
    }
};

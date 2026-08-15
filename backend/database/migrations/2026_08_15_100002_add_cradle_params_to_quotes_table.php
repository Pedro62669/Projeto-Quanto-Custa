<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os parâmetros de construção do berço.
 *
 * Colunas em `quotes`, e não linhas em `quote_components`, pelo mesmo motivo
 * que `SimulateQuoteRequest` já registra sobre o payload: eles descrevem a
 * CONSTRUÇÃO, não o material. A mesma espuma serve a berços de alturas
 * diferentes, e a grade 3×4 não é propriedade do papelão — é do projeto.
 *
 * O armazenamento espelha o formato que a API já aceita. Isso não é estética:
 * é o que permite reconstruir a especificação de um orçamento salvo enviando
 * de volta exatamente o que foi recebido, sem uma camada de tradução no meio
 * onde os dois formatos possam divergir.
 *
 * Todas nullable — a maioria esmagadora das caixas não tem berço nenhum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('cradle_type', 20)->nullable()->after('wrap_material_id')
                ->comment('foam | board_niche | paper_niche | paper_fold | divider_grid');

            $table->unsignedTinyInteger('cradle_rows')->nullable()->after('cradle_type');
            $table->unsignedTinyInteger('cradle_columns')->nullable()->after('cradle_rows');

            /*
             * Fração da altura interna, entre 0,1 e 1. Decimal e não float:
             * 0,65 precisa voltar do banco como 0,65, e o float binário
             * devolveria 0,6499999999999999 — que reabriria o orçamento com
             * uma altura de berço diferente da que foi salva.
             */
            $table->decimal('cradle_height_ratio', 4, 3)->nullable()->after('cradle_columns');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn([
                'cradle_type', 'cradle_rows', 'cradle_columns', 'cradle_height_ratio',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produção mensal estimada, em unidades — o divisor do rateio da depreciação.
 *
 * Vinha por query string no painel de simulação, o que servia para explorar
 * cenários mas não para o sistema ter UMA resposta. A pergunta "quanto a
 * depreciação pesa em cada peça" precisa de um número estável e versionado
 * junto do resto da configuração, senão duas telas mostram valores diferentes
 * e nenhuma está errada.
 *
 * Default 75: é a ordem de grandeza de um ateliê de cartonagem que produz sob
 * medida — não uma fábrica. Quem produz mais dilui mais, e o campo existe
 * justamente para o usuário corrigir a estimativa quando conhecer a própria
 * escala.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_settings', function (Blueprint $table) {
            $table->unsignedInteger('monthly_production_volume')
                ->default(75)
                ->after('company_includes_depreciation')
                ->comment('Produção mensal estimada em unidades — divisor do rateio da depreciação');
        });
    }

    public function down(): void
    {
        Schema::table('cost_settings', function (Blueprint $table) {
            $table->dropColumn('monthly_production_volume');
        });
    }
};

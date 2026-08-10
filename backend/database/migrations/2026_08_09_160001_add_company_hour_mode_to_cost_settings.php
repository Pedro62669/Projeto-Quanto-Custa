<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga o parque de máquinas e as despesas fixas ao motor de preço.
 *
 * Até aqui o motor cobrava indireto por ESTIMATIVA: `labor_hour_rate` era um
 * R$/h digitado e `overhead_percent` um palpite de rateio. Os módulos de
 * equipamentos e custos fixos permitem calcular o mesmo número a partir de
 * dados reais — e é disso que trata este modo.
 *
 * SUBSTITUIÇÃO, não soma. Ligar o modo e manter as estimativas cobraria o
 * mesmo dinheiro duas vezes:
 *
 *   • aluguel, contador e pró-labore já estão na hora-empresa — somar
 *     `overhead_percent` por cima os cobraria de novo;
 *   • a depreciação das máquinas entra na hora-empresa quando
 *     `company_includes_depreciation` está ligado, e `machine_hour_rate` foi
 *     definido como "depreciação + manutenção" — nesse caso o campo passa a
 *     valer MANUTENÇÃO apenas, e o comentário da coluna é atualizado aqui.
 *
 * O default é DESLIGADO de propósito. `cost_settings` é versionada: ligar o
 * modo é publicar uma versão nova, e os orçamentos já emitidos continuam
 * apontando para a versão antiga com o preço que foi combinado com o cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_settings', function (Blueprint $table) {
            $table->boolean('use_company_hour')->default(false)->after('labor_hour_rate')
                ->comment('Substitui labor_hour_rate e overhead_percent pela hora-empresa calculada');

            // Jornada usada para converter despesa mensal em custo por minuto.
            $table->decimal('company_hours_per_day', 4, 2)->default(8.00)->after('use_company_hour');
            $table->decimal('company_days_per_month', 4, 1)->default(22.0)->after('company_hours_per_day');

            /**
             * Fator de eficiência preferido do usuário, finalmente persistido.
             *
             * Vinha por query string no painel de simulação, o que era certo
             * para explorar cenários — mas o motor de preço precisa de UM valor
             * estável, versionado junto do resto, senão o mesmo orçamento
             * mudaria de preço conforme quem simulou.
             */
            $table->unsignedTinyInteger('company_efficiency_percent')->default(85)
                ->after('company_days_per_month')
                ->comment('100, 85 ou 75 — ver EfficiencyScenario');

            $table->boolean('company_includes_depreciation')->default(true)
                ->after('company_efficiency_percent')
                ->comment('Soma a depreciação do parque à base da hora-empresa');
        });
    }

    public function down(): void
    {
        Schema::table('cost_settings', function (Blueprint $table) {
            $table->dropColumn([
                'use_company_hour',
                'company_hours_per_day',
                'company_days_per_month',
                'company_efficiency_percent',
                'company_includes_depreciation',
            ]);
        });
    }
};

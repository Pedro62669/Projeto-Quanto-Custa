<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custos fixos do sistema (energia, hora/máquina, hora/mão de obra).
 *
 * Modelado como tabela versionada em vez de linha única: quando a tarifa de
 * energia sobe, o admin cria uma nova versão e os orçamentos antigos
 * continuam explicáveis. A linha vigente é a de maior `effective_from`
 * já iniciada — ver CostSetting::current().
 *
 * Preferimos colunas tipadas a um key/value genérico: o cálculo depende
 * desses campos e uma coluna ausente deve quebrar em migration, não em runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_settings', function (Blueprint $table) {
            $table->id();

            // --- Energia ------------------------------------------------
            $table->decimal('energy_tariff_per_kwh', 10, 4)
                ->comment('R$ por kWh cobrado pela concessionária');

            // --- Máquina ------------------------------------------------
            $table->decimal('machine_hour_rate', 10, 2)
                ->comment('R$/h de depreciação + manutenção (sem energia)');
            $table->decimal('machine_power_kw', 8, 3)
                ->comment('Potência média do parque em kW — converte horas em kWh');

            // --- Mão de obra --------------------------------------------
            $table->decimal('labor_hour_rate', 10, 2)
                ->comment('R$/h já com encargos');

            // --- Rateios e impostos --------------------------------------
            $table->decimal('overhead_percent', 5, 2)->default(0)
                ->comment('Rateio de custos indiretos (%) sobre o custo direto');
            $table->decimal('tax_percent', 5, 2)->default(0)
                ->comment('Impostos sobre venda (%) — aplicados sobre o preço final');
            $table->decimal('default_profit_margin_percent', 5, 2)->default(30.00);

            $table->char('currency', 3)->default('BRL');

            $table->timestamp('effective_from')->useCurrent();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('effective_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_settings');
    }
};

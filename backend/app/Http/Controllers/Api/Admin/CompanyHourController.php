<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\EfficiencyScenario;
use App\Http\Controllers\Controller;
use App\Models\CostSetting;
use App\Services\Pricing\CompanyHourCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Custo da hora e do minuto da empresa.
 *
 * GET, e nada é gravado: o painel é um simulador. O usuário mexe na jornada, no
 * fator e no botão de depreciação para VER o efeito, e a maior parte dessas
 * combinações ele nem quer guardar. Persistir cada ajuste transformaria uma
 * exploração em histórico de configuração que ninguém pediu.
 */
class CompanyHourController extends Controller
{
    /** Jornada padrão de referência: 8h × 22 dias = 176 horas pagas no mês. */
    private const HORAS_POR_DIA = 8;

    private const DIAS_POR_MES = 22;

    /** Produção mensal de referência — ateliê sob medida, não fábrica. */
    private const PRODUCAO_MENSAL = 75;

    public function __invoke(Request $request, CompanyHourCalculator $calculator): JsonResponse
    {
        $validated = $request->validate([
            'hours_per_day' => ['nullable', 'numeric', 'min:0.5', 'max:24'],
            'days_per_month' => ['nullable', 'numeric', 'min:1', 'max:31'],

            /*
             * O fator é enum, não número livre. Aceitar 93% daria uma precisão
             * que o dado não tem: ninguém mede a própria eficiência com essa
             * resolução, e o número inventado passaria a sustentar um preço.
             * Três cenários calibrados são mais honestos que um campo aberto.
             */
            'efficiency_percent' => ['nullable', Rule::enum(EfficiencyScenario::class)],

            'include_depreciation' => ['nullable', 'boolean'],

            // Sobrepõe o volume gravado só nesta simulação — o painel serve
            // para responder "e se eu produzir o dobro?" sem publicar nada.
            'monthly_production_volume' => ['nullable', 'integer', 'min:1', 'max:10000000'],
        ], [
            'efficiency_percent.enum' => 'O fator de eficiência deve ser 100, 85 ou 75.',
            'hours_per_day.max' => 'O dia tem 24 horas.',
            'days_per_month.max' => 'O mês tem no máximo 31 dias.',
        ]);

        /*
         * Os defaults saem da configuração VIGENTE quando ela existe.
         *
         * O painel abre mostrando o que a empresa já publicou, e não os
         * genéricos 8×22×85 — abrir com números que não são os dela faria o
         * usuário achar que perdeu a configuração. Os genéricos só valem para
         * quem ainda não publicou nada.
         */
        $vigente = rescue(fn () => CostSetting::current(), null, report: false);

        $dados = $calculator->calculate(
            hoursPerDay: (float) ($validated['hours_per_day']
                ?? $vigente?->company_hours_per_day
                ?? self::HORAS_POR_DIA),

            daysPerMonth: (float) ($validated['days_per_month']
                ?? $vigente?->company_days_per_month
                ?? self::DIAS_POR_MES),

            // 85% é o default por ser o cenário recomendado — quem não escolheu
            // ainda recebe a estimativa realista, não a otimista.
            active: EfficiencyScenario::tryFrom(
                (int) ($validated['efficiency_percent'] ?? $vigente?->company_efficiency_percent ?? 0)
            ) ?? EfficiencyScenario::Recommended,

            /*
             * Default TRUE: a depreciação entra a menos que o usuário a exclua.
             *
             * O default de um sistema de precificação tem que ser o que protege
             * quem usa. Esquecer de LIGAR a depreciação faz a empresa vender
             * barato por anos e descobrir no fim que não tem como repor a
             * máquina; esquecer de desligá-la faz vender um pouco mais caro.
             * Só um dos dois erros quebra o negócio.
             */
            includeDepreciation: (bool) ($validated['include_depreciation']
                ?? $vigente?->company_includes_depreciation
                ?? true),

            monthlyProduction: (float) ($validated['monthly_production_volume']
                ?? $vigente?->monthly_production_volume
                ?? self::PRODUCAO_MENSAL),
        );

        return response()->json(['data' => $dados]);
    }
}

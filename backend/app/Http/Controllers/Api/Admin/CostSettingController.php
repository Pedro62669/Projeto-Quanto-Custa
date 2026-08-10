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
 * Custos fixos do sistema (somente admin).
 *
 * Não existe UPDATE: alterar uma tarifa cria uma NOVA versão. Editar a linha
 * vigente reescreveria a base de cálculo de orçamentos já emitidos e tornaria
 * o histórico inexplicável.
 */
class CostSettingController extends Controller
{
    /** GET /api/admin/cost-settings — histórico de versões. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CostSetting::with('author:id,name')
                ->latest('effective_from')
                ->paginate(20),
        ]);
    }

    /**
     * GET /api/admin/cost-settings/current — versão vigente.
     *
     * O `company_minute_cost` vai junto porque é ele que o motor TypeScript do
     * preview usa. Sem isso o navegador teria que somar despesas fixas e
     * depreciação por conta própria — e duas implementações da mesma soma são
     * exatamente o tipo de divergência que a suíte de paridade existe para
     * impedir. O número é calculado uma vez, aqui, e as duas pontas o consomem.
     */
    public function current(CompanyHourCalculator $companyHour): JsonResponse
    {
        $settings = CostSetting::current();

        return response()->json([
            'data' => [
                ...$settings->toArray(),
                'company_minute_cost' => $companyHour->minuteCostFor($settings),
            ],
        ]);
    }

    /** POST /api/admin/cost-settings — publica uma nova versão. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'energy_tariff_per_kwh' => ['required', 'numeric', 'min:0', 'max:100'],
            'machine_hour_rate' => ['required', 'numeric', 'min:0', 'max:100000'],
            'machine_power_kw' => ['required', 'numeric', 'min:0', 'max:10000'],
            'labor_hour_rate' => ['required', 'numeric', 'min:0', 'max:100000'],

            'overhead_percent' => ['nullable', 'numeric', 'min:0', 'max:500'],
            // Abaixo de 100% porque o imposto é calculado "por dentro"
            // (preço ÷ (1 − alíquota)) — em 100% a divisão explode.
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],

            'default_profit_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'currency' => ['nullable', 'string', 'size:3'],

            // Permite agendar um reajuste para a virada do mês.
            'effective_from' => ['nullable', 'date'],

            /*
             * Modo hora-empresa. Ligar substitui `labor_hour_rate` e zera
             * `overhead_percent` no motor — ver PricingEngine.
             */
            'use_company_hour' => ['boolean'],
            'company_hours_per_day' => ['nullable', 'numeric', 'min:0.5', 'max:24'],
            'company_days_per_month' => ['nullable', 'numeric', 'min:1', 'max:31'],
            'company_efficiency_percent' => ['nullable', Rule::enum(EfficiencyScenario::class)],
            'company_includes_depreciation' => ['boolean'],

            // Divisor do rateio da depreciação por peça. min:1 é o que impede
            // a divisão por zero antes de ela chegar ao motor.
            'monthly_production_volume' => ['nullable', 'integer', 'min:1', 'max:10000000'],
        ], [
            'company_efficiency_percent.enum' => 'O fator de eficiência deve ser 100, 85 ou 75.',
        ]);

        /*
         * Aviso de dupla contagem, não bloqueio.
         *
         * `machine_hour_rate` foi definido como "depreciação + manutenção". Com
         * a hora-empresa já somando a depreciação do parque, manter o valor
         * cheio cobra a mesma máquina duas vezes. Não dá para separar as duas
         * parcelas de um número só, e recusar a publicação seria pior: o
         * usuário talvez tenha máquinas fora do inventário, e só ele sabe.
         * Então o sistema avisa e deixa passar.
         */
        $avisos = [];

        if (($validated['use_company_hour'] ?? false)
            && ($validated['company_includes_depreciation'] ?? true)
            && $validated['machine_hour_rate'] > 0) {
            $avisos[] = 'A hora-empresa já inclui a depreciação das máquinas. '
                .'Ajuste a hora-máquina para cobrir apenas MANUTENÇÃO, senão a depreciação será cobrada duas vezes.';
        }

        if (($validated['use_company_hour'] ?? false) && ($validated['overhead_percent'] ?? 0) > 0) {
            $avisos[] = 'Com a hora-empresa ligada o rateio percentual é ignorado pelo motor: '
                .'os custos indiretos já entram pelo custo do minuto.';
        }

        $setting = CostSetting::create([
            ...$validated,
            'effective_from' => $validated['effective_from'] ?? now(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(
            array_filter([
                'data' => $setting,
                'warnings' => $avisos ?: null,
            ]),
            JsonResponse::HTTP_CREATED,
        );
    }
}

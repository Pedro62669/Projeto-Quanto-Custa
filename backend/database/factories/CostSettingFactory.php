<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CostSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CostSetting> */
class CostSettingFactory extends Factory
{
    protected $model = CostSetting::class;

    /**
     * Valores neutros por padrão (rateio e imposto zerados) para que os testes
     * do motor isolem uma variável de cada vez. Estados nomeados ligam cada
     * componente quando o teste precisar dele.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'energy_tariff_per_kwh' => 1.00,
            'machine_hour_rate' => 60.00,
            'machine_power_kw' => 10.00,
            'labor_hour_rate' => 30.00,
            'overhead_percent' => 0.0,
            'tax_percent' => 0.0,
            'default_profit_margin_percent' => 30.0,
            'currency' => 'BRL',
            'effective_from' => now(),
        ];
    }

    public function withOverhead(float $percent = 10.0): static
    {
        return $this->state(['overhead_percent' => $percent]);
    }

    public function withTax(float $percent = 10.0): static
    {
        return $this->state(['tax_percent' => $percent]);
    }
}

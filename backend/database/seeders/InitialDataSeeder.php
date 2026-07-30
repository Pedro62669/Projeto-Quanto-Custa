<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Enums\UserRole;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dados mínimos para o sistema funcionar.
 *
 * A configuração de custos é OBRIGATÓRIA: CostSetting::current() lança exceção
 * sem ela, e nenhum orçamento pode ser calculado. Por isso ela é semeada aqui,
 * e não deixada a cargo do primeiro acesso do admin.
 */
class InitialDataSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * Credenciais do admin inicial via .env, para que produção não herde
         * uma senha versionada em repositório público. O default só existe
         * para o ambiente local funcionar sem configuração.
         */
        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@quantocusta.local')],
            [
                'name' => 'Administrador',
                'password' => env('ADMIN_PASSWORD', 'admin123'),
                'role' => UserRole::Admin,
            ],
        );

        CostSetting::firstOrCreate(
            ['effective_from' => now()->startOfYear()],
            [
                'energy_tariff_per_kwh' => 0.92,   // R$/kWh
                'machine_hour_rate' => 45.00,  // depreciação + manutenção
                'machine_power_kw' => 7.50,
                'labor_hour_rate' => 28.00,  // já com encargos
                'overhead_percent' => 12.00,
                'tax_percent' => 8.00,
                'default_profit_margin_percent' => 30.00,
                'created_by' => $admin->id,
            ],
        );

        $materials = [
            [
                'name' => 'Papelão ondulado E (1,5mm)',
                'type' => MaterialType::Cardboard,
                'cost_unit' => MaterialUnit::SquareMeter,
                'cost_per_unit' => 3.20,
                'default_waste_percent' => 12.00,
                'thickness_mm' => 1.50,
                'color_hex' => '#C8A06A',
            ],
            [
                'name' => 'Papelão ondulado B (3mm)',
                'type' => MaterialType::Cardboard,
                'cost_unit' => MaterialUnit::SquareMeter,
                'cost_per_unit' => 4.10,
                'default_waste_percent' => 12.00,
                'thickness_mm' => 3.00,
                'color_hex' => '#B8905C',
            ],
            [
                // Cotado em kg: a gramatura converte para R$/m² no cálculo.
                'name' => 'Papel kraft 300g',
                'type' => MaterialType::Paper,
                'cost_unit' => MaterialUnit::Kilogram,
                'cost_per_unit' => 8.50,
                'grammage_kg_per_m2' => 0.300,
                'default_waste_percent' => 8.00,
                'thickness_mm' => 0.40,
                'color_hex' => '#D6B98C',
            ],
            [
                'name' => 'Tecido algodão cru',
                'type' => MaterialType::Fabric,
                'cost_unit' => MaterialUnit::Kilogram,
                'cost_per_unit' => 24.00,
                'grammage_kg_per_m2' => 0.180,
                'default_waste_percent' => 15.00,
                'thickness_mm' => 0.60,
                'color_hex' => '#E8E0D0',
            ],
        ];

        foreach ($materials as $material) {
            Material::firstOrCreate(['name' => $material['name']], $material);
        }
    }
}

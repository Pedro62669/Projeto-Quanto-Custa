<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Enums\UserRole;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Põe uma empresa nova de pé, pronta para calcular no primeiro acesso.
 *
 * O detalhe que decide este service é invisível no requisito: uma empresa sem
 * CostSetting NÃO CALCULA NADA. `CostSetting::current()` lança DomainException,
 * e a calculadora — que é o produto — devolveria erro na primeira tela. Criar só
 * `tenants` + `users` entregaria uma conta quebrada.
 *
 * Existe em vez de o controller repetir o que o InitialDataSeeder já fazia
 * porque os dois caminhos precisam produzir o MESMO estado. Duas listas de
 * materiais e dois conjuntos de custos padrão divergiriam no primeiro ajuste, e
 * a divergência só apareceria em produção, na conta de um cliente real. O seeder
 * agora chama isto aqui.
 *
 * Roda sem usuário autenticado (seeder, comando) e com usuário nenhum ainda
 * criado (cadastro público): todo vínculo de empresa é declarado à mão, porque a
 * trait BelongsToTenant não tem de onde tirá-lo.
 */
class ProvisionaEmpresa
{
    /**
     * Custos iniciais.
     *
     * Números plausíveis para uma cartonagem pequena no Brasil, não zeros. Zero
     * produziria um preço que parece ótimo e está errado — e o usuário novo não
     * tem como saber que o número que ele viu não incluía energia nem mão de
     * obra. Errar para o lado de um custo real e ajustável é mais honesto do que
     * errar para o lado de um custo ausente.
     *
     * @var array<string, float>
     */
    private const CUSTOS_PADRAO = [
        'energy_tariff_per_kwh' => 0.92,   // R$/kWh
        'machine_hour_rate' => 45.00,  // depreciação + manutenção
        'machine_power_kw' => 7.50,
        'labor_hour_rate' => 28.00,  // já com encargos
        'overhead_percent' => 12.00,
        'tax_percent' => 8.00,
        'default_profit_margin_percent' => 30.00,
    ];

    /**
     * Cria empresa, administrador, custos e o estoque inicial de matérias-primas.
     *
     * Tudo numa transação: uma empresa que existe sem custos é pior do que uma
     * empresa que não existe — a primeira o usuário descobre quebrada depois de
     * já ter confiado a senha dela.
     */
    public function executar(
        string $nomeDaEmpresa,
        string $nomeDoResponsavel,
        string $email,
        string $senha,
        ?string $documento = null,
        bool $comTeste = true,
    ): Tenant {
        return DB::transaction(function () use (
            $nomeDaEmpresa, $nomeDoResponsavel, $email, $senha, $documento, $comTeste
        ): Tenant {
            $tenant = Tenant::create([
                'name' => $nomeDaEmpresa,
                'document' => $documento,
                'email' => $email,
                'is_active' => true,
            ]);

            if ($comTeste) {
                $this->iniciaTeste($tenant);
            }

            $admin = new User([
                'name' => $nomeDoResponsavel,
                'email' => $email,
                'password' => $senha,
                'role' => UserRole::Admin,
            ]);

            /*
             * forceFill porque `tenant_id` está FORA do $fillable de User, e isso
             * é deliberado: preenchível, ele viraria escalação de privilégio —
             * um payload com "tenant_id": null criaria um admin de PLATAFORMA,
             * que enxerga todas as empresas.
             */
            $admin->forceFill(['tenant_id' => $tenant->id])->save();

            CostSetting::create([
                'tenant_id' => $tenant->id,
                'effective_from' => now()->startOfYear(),
                'created_by' => $admin->id,
                ...self::CUSTOS_PADRAO,
            ]);

            foreach ($this->materiaisIniciais() as $material) {
                Material::create(['tenant_id' => $tenant->id, ...$material]);
            }

            return $tenant->refresh();
        });
    }

    /**
     * Abre o período de teste.
     *
     * Cotas do Profissional por alguns dias — ver `billing.dias_de_teste`.
     *
     * `subscription_ends_at` fica NULL de propósito, e é a linha mais importante
     * do método. Teste vencido não é assinatura vencida: preencher esse campo
     * jogaria a empresa no EnsureSubscriptionIsActive e ela seria BLOQUEADA ao
     * fim do teste, quando a regra é ser REBAIXADA para o gratuito. Quem estava
     * avaliando o produto não pode ser tratado como inadimplente.
     */
    private function iniciaTeste(Tenant $tenant): void
    {
        $dias = (int) config('billing.dias_de_teste', 3);

        // forceFill: os campos de plano estão fora do $fillable do Tenant, para
        // que nenhuma tela de perfil vire um upgrade grátis.
        $tenant->forceFill([
            'plan_type' => PlanType::Pro,
            'plan_status' => PlanStatus::Trialing,
            'trial_ends_at' => now()->addDays($dias),
            'subscription_ends_at' => null,
        ])->save();
    }

    /**
     * Estoque inicial de matérias-primas.
     *
     * Quatro itens comuns em cartonagem rígida, com gramatura e espessura
     * preenchidas — a espessura alimenta o 3D e a gramatura converte kg para m²
     * no cálculo. Uma conta que abre com a lista vazia obriga o usuário a
     * cadastrar material antes de ver o produto funcionar uma única vez.
     *
     * @return list<array<string, mixed>>
     */
    private function materiaisIniciais(): array
    {
        return [
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
    }
}

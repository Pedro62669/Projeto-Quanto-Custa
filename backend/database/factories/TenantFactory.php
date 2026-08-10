<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'is_active' => true,

            /*
             * Plano SEM limites como padrão da suíte, ao contrário do banco, que
             * nasce no gratuito.
             *
             * Mesma razão do daSuite() logo abaixo: um teste de precificação que
             * cadastra doze materiais estaria falhando por causa da cota do
             * plano — um motivo que não é o dele, num arquivo que não fala de
             * cobrança. Quem testa cota escolhe o plano explicitamente, com
             * ->gratuito() ou ->noPlano(), e é justamente aí que o limite vira o
             * assunto do teste.
             */
            'plan_type' => PlanType::Pro,
            'plan_status' => PlanStatus::Active,
        ];
    }

    /** Plano gratuito — os tetos apertados. É o default de quem se cadastra. */
    public function gratuito(): static
    {
        return $this->state([
            'plan_type' => PlanType::Free,
            'plan_status' => PlanStatus::Trialing,
        ]);
    }

    public function noPlano(PlanType $plano, PlanStatus $situacao = PlanStatus::Active): static
    {
        return $this->state([
            'plan_type' => $plano,
            'plan_status' => $situacao,
        ]);
    }

    /** Assinatura vencida: o acesso de escrita cai, a leitura continua. */
    public function vencido(): static
    {
        return $this->state([
            'plan_status' => PlanStatus::PastDue,
            'subscription_ends_at' => now()->subDay(),
        ]);
    }

    /**
     * A empresa corrente da suíte: a primeira que existir, ou uma nova.
     *
     * É o default de tenant_id nas outras factories, e o que permite que testes
     * escritos antes do multi-inquilino continuem válidos sem uma linha de
     * mudança: usuário, material e configuração criados soltos caem todos na
     * MESMA empresa, que é o cenário que eles sempre descreveram. Uma factory
     * por model criaria uma empresa por objeto, e o escopo esconderia cada um
     * do outro — os testes quebrariam em massa por um motivo que não é o deles.
     *
     * Quem for testar isolamento cruzado passa `tenant_id` explícito e ignora
     * este default — ver TenantIsolationTest.
     */
    public static function daSuite(): int
    {
        return (int) (Tenant::query()->value('id') ?? self::new()->create()->id);
    }

    /**
     * Empresa com o cadastro fiscal completo.
     *
     * Existe porque o caminho que EXIGE esses campos (emissão de PDF, dados do
     * rodapé do orçamento) precisa testar com eles presentes, enquanto o resto
     * da suíte não deve pagar o custo de preenchê-los.
     */
    public function completo(): static
    {
        return $this->state(fn () => [
            'legal_name' => fake()->company().' LTDA',
            'document' => (string) fake()->unique()->numerify('##############'),
            'email' => fake()->unique()->companyEmail(),
            'whatsapp' => '5511'.fake()->numerify('#########'),
            'instagram' => '@'.fake()->userName(),
            'postal_code' => fake()->numerify('########'),
            'street' => fake()->streetName(),
            'street_number' => (string) fake()->buildingNumber(),
            'district' => fake()->citySuffix(),
            'city' => fake()->city(),
            'state' => 'SP',
        ]);
    }

    public function inativo(): static
    {
        return $this->state(['is_active' => false]);
    }
}

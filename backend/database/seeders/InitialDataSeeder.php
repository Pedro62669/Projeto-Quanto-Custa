<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Models\Tenant;
use App\Services\Onboarding\ProvisionaEmpresa;
use Illuminate\Database\Seeder;

/**
 * A empresa de demonstração da instalação local.
 *
 * Desde o cadastro público, este seeder NÃO monta mais os dados à mão: ele chama
 * o mesmo ProvisionaEmpresa que atende o `POST /api/register`. A razão é que
 * duas listas de materiais e dois conjuntos de custos padrão divergiriam no
 * primeiro ajuste, e a divergência só apareceria em produção — na conta de um
 * cliente real, não aqui.
 *
 * Manter o seeder como um CHAMADOR tem um efeito colateral bom: rodar
 * `migrate:fresh --seed` passa a exercitar o caminho de provisionamento de
 * verdade. Se ele quebrar, quebra no primeiro comando do dia, não no primeiro
 * cliente.
 */
class InitialDataSeeder extends Seeder
{
    public function run(): void
    {
        $nome = env('TENANT_NAME', 'Empresa demonstração');

        // Idempotente: `migrate:fresh --seed` roda o tempo todo, e um segundo
        // provisionamento estouraria no unique de `users.email`.
        if (Tenant::query()->where('name', $nome)->exists()) {
            return;
        }

        $tenant = app(ProvisionaEmpresa::class)->executar(
            nomeDaEmpresa: $nome,

            /*
             * Credenciais via .env, para que produção não herde uma senha
             * versionada em repositório público. O default só existe para o
             * ambiente local subir sem configuração.
             */
            nomeDoResponsavel: 'Administrador',
            email: env('ADMIN_EMAIL', 'admin@quantocusta.local'),
            senha: env('ADMIN_PASSWORD', 'admin123'),

            /*
             * Sem período de teste: um teste de 3 dias faria a instalação local
             * rebaixar sozinha no meio da semana e alguém perderia uma tarde
             * descobrindo por que a cota apertou.
             */
            comTeste: false,
        );

        /*
         * Demonstração no Profissional, e não no gratuito.
         *
         * O ambiente local não deve brigar com cota: quem está desenvolvendo
         * cadastra vinte materiais de teste sem que isso signifique nada sobre o
         * plano. Quem testa cota escolhe o plano explicitamente — ver
         * TenantFactory::gratuito().
         */
        $tenant->forceFill([
            'plan_type' => PlanType::Pro,
            'plan_status' => PlanStatus::Active,
        ])->save();

        /*
         * Um admin de PLATAFORMA (tenant_id nulo) não é semeado de propósito:
         * ele atravessa todos os inquilinos, e criar um por padrão em toda
         * instalação seria uma conta privilegiada de senha conhecida.
         */
    }
}

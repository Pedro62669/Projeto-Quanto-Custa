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
        );

        /*
         * Demonstração no Profissional e com período pago em aberto.
         *
         * A empresa nasce sem plano — é o estado real de quem se cadastra — e o
         * seeder a promove porque o ambiente local não deve brigar com cota nem
         * com o bloqueio de escrita: quem está desenvolvendo cadastra vinte
         * materiais de teste sem que isso diga nada sobre plano.
         *
         * `subscription_ends_at` é a linha que importa. Sem ela, a instalação
         * local abriria em modo somente-leitura e o primeiro POST devolveria 403
         * — ver Tenant::acessoLiberado(). Um ano à frente para não vencer no meio
         * de uma semana de trabalho.
         */
        $tenant->forceFill([
            'plan_type' => PlanType::Pro,
            'plan_status' => PlanStatus::Active,
            'subscription_ends_at' => now()->addYear(),
        ])->save();

        /*
         * Um admin de PLATAFORMA (tenant_id nulo) não é semeado de propósito:
         * ele atravessa todos os inquilinos, e criar um por padrão em toda
         * instalação seria uma conta privilegiada de senha conhecida.
         */
    }
}

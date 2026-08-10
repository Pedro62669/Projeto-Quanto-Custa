<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\ConviteDeRetorno;
use App\Models\EngagementEmail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Reengajamento de quem parou de usar o sistema.
 *
 * O risco que este comando existe para conter não é o usuário esquecer o
 * sistema: é o usuário voltar a orçar com custo defasado. Uma cartonagem que
 * some por dois meses e volta precificando com o papelão do bimestre anterior
 * aprova pedidos no prejuízo — e conclui que a ferramenta não funciona.
 *
 * Roda diariamente (ver routes/console.php). Três travas contra virar spam:
 * o usuário precisa ter sumido de fato, não pode ter recebido nada há pouco, e
 * pode se descadastrar em um clique.
 */
class SendEngagementEmails extends Command
{
    protected $signature = 'app:send-engagement-emails
                            {--dias=10 : Dias sem acesso para o usuário entrar na lista}
                            {--intervalo=15 : Dias mínimos entre dois e-mails para a mesma pessoa}
                            {--limite=200 : Teto de disparos por execução}
                            {--dry-run : Lista quem receberia, sem enviar}';

    protected $description = 'Envia e-mail de reengajamento para usuários inativos';

    public function handle(): int
    {
        $dias = max((int) $this->option('dias'), 1);
        $intervalo = max((int) $this->option('intervalo'), 1);
        $limite = max((int) $this->option('limite'), 1);
        $simulacao = (bool) $this->option('dry-run');

        $corte = now()->subDays($dias);
        $desdeUltimoEnvio = now()->subDays($intervalo);

        $destinatarios = $this->destinatarios($corte, $desdeUltimoEnvio, $limite);

        if ($destinatarios->isEmpty()) {
            $this->info('Nenhum usuário inativo elegível. Nada a enviar.');

            return self::SUCCESS;
        }

        $enviados = 0;
        $falhas = 0;

        foreach ($destinatarios as $usuario) {
            if ($simulacao) {
                $this->line("[simulação] {$usuario->email} — último acesso: "
                    .($usuario->last_login_at?->toDateString() ?? 'nunca'));

                continue;
            }

            try {
                Mail::to($usuario->email)->send(new ConviteDeRetorno($usuario));

                /*
                 * Só registra DEPOIS do envio bem-sucedido.
                 *
                 * Registrar antes protegeria contra duplicata, mas ao custo de
                 * silenciar o usuário por 15 dias por causa de um e-mail que
                 * nunca saiu. Errar para o lado de tentar de novo amanhã é
                 * melhor do que errar para o lado de nunca mais tentar.
                 */
                EngagementEmail::create([
                    'user_id' => $usuario->id,
                    'tenant_id' => $usuario->tenant_id,
                    'type' => 'inatividade',
                    'sent_at' => now(),
                ]);

                $enviados++;
            } catch (Throwable $e) {
                /*
                 * Uma caixa postal cheia não pode abortar a fila inteira: os
                 * destinatários seguintes ficariam sem e-mail por causa de um
                 * endereço quebrado, e amanhã o comando esbarraria no mesmo.
                 */
                $falhas++;
                $this->warn("Falha ao enviar para {$usuario->email}: {$e->getMessage()}");
            }
        }

        if ($simulacao) {
            $this->info("Simulação: {$destinatarios->count()} usuário(s) receberiam o e-mail.");

            return self::SUCCESS;
        }

        $this->info("Enviados: {$enviados}. Falhas: {$falhas}.");

        return self::SUCCESS;
    }

    /**
     * Quem entra na lista.
     *
     * @return Collection<int, User>
     */
    private function destinatarios(
        Carbon $corte,
        Carbon $desdeUltimoEnvio,
        int $limite,
    ) {
        return User::query()
            ->where('is_active', true)

            /*
             * Oposição do titular (LGPD art. 18, §2º). Primeiro filtro da query
             * de propósito: quem se descadastrou não deveria nem ser lido junto
             * dos demais.
             */
            ->whereNull('marketing_opt_out_at')

            /*
             * Só quem confirmou o endereço.
             *
             * Insistir com um e-mail nunca verificado é a receita para queimar
             * a reputação do domínio: ou o endereço não existe e cada disparo
             * vira bounce, ou ele é de outra pessoa, que nunca pediu nada e vai
             * marcar como spam. Os dois contam contra a entrega dos e-mails que
             * importam — o aviso de fatura sai do mesmo remetente.
             */
            ->whereNotNull('email_verified_at')

            /*
             * Precisa pertencer a uma empresa ATIVA.
             *
             * `whereHas` e não `whereNotNull('tenant_id')`: o admin de
             * plataforma tem tenant nulo e somos nós — mandar e-mail de
             * reengajamento para a própria equipe é ruído. E empresa suspensa ou
             * cancelada não deve receber convite para voltar a usar o que ela
             * não pode mais acessar.
             */
            ->whereHas('tenant', fn ($q) => $q->where('is_active', true))

            /*
             * Sumido: sem acesso desde o corte, OU nunca acessou mas já se
             * cadastrou faz tempo. O segundo caso é o mais importante e o mais
             * fácil de esquecer — quem cria a conta e nunca volta é exatamente
             * quem a campanha precisa alcançar, e o campo dele é null.
             */
            ->where(function ($q) use ($corte): void {
                $q->where('last_login_at', '<', $corte)
                    ->orWhere(fn ($sub) => $sub
                        ->whereNull('last_login_at')
                        ->where('created_at', '<', $corte));
            })

            // Não recebeu nada recentemente.
            ->whereDoesntHave('engagementEmails', fn ($q) => $q->where('sent_at', '>=', $desdeUltimoEnvio))

            ->orderBy('id')
            ->limit($limite)
            ->get();
    }
}

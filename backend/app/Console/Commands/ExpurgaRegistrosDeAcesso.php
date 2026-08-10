<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AccessLog;
use Illuminate\Console\Command;

/**
 * Expurgo dos registros de acesso vencidos.
 *
 * O Marco Civil (art. 15) manda guardar por SEIS MESES. É um piso, não um
 * teto — mas a LGPD puxa para o outro lado com o princípio da necessidade
 * (art. 6º, III): dado pessoal não se guarda além do que a finalidade exige.
 * Guardar para sempre resolveria uma lei descumprindo a outra.
 *
 * Seis meses é o default por ser o ponto onde as duas se encontram. Ampliar é
 * decisão jurídica (litígio em curso, ordem judicial), e por isso é parâmetro
 * de linha de comando em vez de constante escondida no código.
 */
class ExpurgaRegistrosDeAcesso extends Command
{
    protected $signature = 'compliance:expurgar-acessos
                            {--meses=6 : Retenção em meses; o mínimo legal é 6}
                            {--force : Executa mesmo abaixo do mínimo legal}';

    protected $description = 'Remove registros de acesso mais antigos que o prazo de retenção';

    public function handle(): int
    {
        $meses = (int) $this->option('meses');

        if ($meses < 6 && ! $this->option('force')) {
            $this->error(
                "Retenção de {$meses} meses viola o mínimo de 6 do Marco Civil (art. 15). "
                .'Use --force se houver fundamento jurídico para isso.'
            );

            return self::FAILURE;
        }

        $limite = now()->subMonths($meses);

        /*
         * delete() do query builder, e não do model: o AccessLog barra
         * `deleting` de propósito, para que nenhum caminho da aplicação apague
         * um registro isolado. O expurgo por retenção é a única remoção
         * legítima, e passa por fora do Eloquent justamente para ser o único
         * lugar do código capaz de fazê-la.
         */
        $removidos = AccessLog::query()
            ->where('occurred_at', '<', $limite)
            ->delete();

        $this->info("Removidos {$removidos} registro(s) anteriores a {$limite->toDateString()}.");

        return self::SUCCESS;
    }
}

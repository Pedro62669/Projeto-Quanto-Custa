<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Support\Carbon;

/**
 * O que aconteceu ao cancelar — o que o usuário precisa ler na tela.
 */
final readonly class CancelamentoResult
{
    public function __construct(
        public bool $reembolsado,
        public float $valorReembolsado,
        /** Até quando o acesso continua. Agora, no caso de reembolso. */
        public Carbon $acessoAte,
        public string $mensagem,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reembolsado' => $this->reembolsado,
            'valor_reembolsado' => round($this->valorReembolsado, 2),
            'acesso_ate' => $this->acessoAte->toIso8601String(),
            'message' => $this->mensagem,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExcluirContaRequest;
use App\Services\Compliance\ExclusaoDeConta;
use Illuminate\Http\JsonResponse;

/**
 * A conta da empresa, do ponto de vista de quem é dono dela.
 */
class AccountController extends Controller
{
    /**
     * Exclusão definitiva da conta — LGPD, direito ao esquecimento.
     *
     * Devolve 200 com o inventário do que foi apagado, e não 204. A LGPD dá ao
     * titular o direito de saber o que foi eliminado, e um corpo vazio não
     * comprova nada: este JSON é o recibo que ele pode guardar.
     *
     * O token da requisição morre junto com o usuário, dentro do service. Não
     * há nada a revogar depois — a resposta já sai de uma sessão que não existe
     * mais, e é por isso que o inventário é montado ANTES do delete.
     */
    public function destroy(ExcluirContaRequest $request, ExclusaoDeConta $exclusao): JsonResponse
    {
        $tenant = $request->user()->tenant;

        $inventario = $exclusao->executar($tenant);

        return response()->json([
            'message' => 'Conta excluída em definitivo.',
            'data' => [
                'excluido_em' => now()->toIso8601String(),
                'inventario' => $inventario,
                /*
                 * Transparência sobre o que sobra, porque sobra de propósito:
                 * o titular tem direito de saber que os registros de acesso
                 * permanecem, por que permanecem e por quanto tempo.
                 */
                'retencao_legal' => [
                    'registros_de_acesso' => 'anonimizados e mantidos por 6 meses',
                    'fundamento' => 'Marco Civil da Internet, art. 15 (LGPD art. 16, I)',
                ],
            ],
        ]);
    }
}

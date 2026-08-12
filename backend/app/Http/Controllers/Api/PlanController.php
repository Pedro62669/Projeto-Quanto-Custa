<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\PlanType;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * A tabela de preços, aberta.
 *
 * Único endpoint do sistema que devolve dado de negócio sem token, e de
 * propósito: quem chega ao site ainda não tem conta, e esconder o preço atrás do
 * cadastro é pedir que a pessoa se comprometa antes de saber quanto custa.
 *
 * Não expõe nada além do que já está na vitrine — nomes de plano, mensalidade e
 * cotas. Nenhuma consulta ao banco: a resposta é montada do enum e da
 * configuração, o que a torna barata o bastante para a página inicial chamar a
 * cada revalidação sem pesar.
 *
 * O prazo de teste e o de arrependimento vêm juntos porque a landing os anuncia
 * como promessa ("3 dias grátis", "7 dias para desistir"). São compromissos que
 * o servidor cumpre — `billing.dias_de_teste` abre o teste em ProvisionaEmpresa
 * e `dias_de_arrependimento` decide o estorno no cancelamento. Escrevê-los à mão
 * no HTML criaria uma promessa que ninguém garante.
 */
class PlanController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'planos' => PlanType::catalogo(),
                'dias_de_teste' => (int) config('billing.dias_de_teste', 3),
                'dias_de_arrependimento' => (int) config('billing.dias_de_arrependimento', 7),
            ],
        ]);
    }
}

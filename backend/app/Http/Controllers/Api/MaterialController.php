<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\BoxModel;
use App\Http\Controllers\Controller;
use App\Http\Resources\MaterialResource;
use App\Models\CostSetting;
use App\Models\Material;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Leitura das "variáveis de cálculo" pelo usuário comum.
 *
 * Separado do controller de admin porque o contrato é outro: aqui só há
 * leitura, só materiais ativos, e sem exposição do preço de compra bruto
 * (filtrado no MaterialResource).
 */
class MaterialController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return MaterialResource::collection(
            Material::active()->orderBy('name')->get()
        );
    }

    /**
     * GET /api/pricing/parameters
     *
     * Bootstrap do formulário: uma única chamada devolve tudo que a tela da
     * calculadora precisa para montar seus defaults, evitando um "waterfall"
     * de requisições no carregamento.
     */
    public function parameters(): JsonResponse
    {
        $settings = CostSetting::current();

        return response()->json([
            'data' => [
                'currency' => $settings->currency,
                'default_profit_margin_percent' => $settings->default_profit_margin_percent,
                'tax_percent' => $settings->tax_percent,

                'box_models' => collect(BoxModel::cases())->map(fn (BoxModel $m) => [
                    'value' => $m->value,
                    'label' => $m->label(),
                    'default_production_minutes' => $m->defaultProductionMinutes(),
                ]),

                'limits' => [
                    'dimension_mm' => ['min' => 10, 'max' => 3000],
                    'quantity' => ['min' => 1, 'max' => 1_000_000],
                ],
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimulateQuoteRequest;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\PricingInput;
use App\Services\Pricing\PricingResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Orçamentos do usuário.
 *
 * O Controller é fino de propósito: valida (via FormRequest), orquestra
 * (Material + CostSetting + PricingEngine), persiste e responde. Nenhuma
 * regra de negócio mora aqui — toda a matemática está em PricingEngine.
 */
class QuoteController extends Controller
{
    public function __construct(
        private readonly PricingEngine $pricing,
    ) {}

    /**
     * POST /api/quotes/simulate
     *
     * Cálculo sem persistir. É a rota que o frontend chama em debounce enquanto
     * o usuário digita, e é ela que dá a palavra final: o preview local do
     * Next.js serve para fluidez visual, mas o número exibido como definitivo
     * vem daqui.
     */
    public function simulate(SimulateQuoteRequest $request): JsonResponse
    {
        $result = $this->calculateFrom($request->validated(), $material, $settings);

        return response()->json([
            'data' => $result->toArray() + [
                'currency' => $settings->currency,
                'engine_version' => PricingEngine::VERSION,
                // Devolvido para que o Canvas 3D pinte a caixa com a cor/textura
                // do material sem precisar de uma segunda requisição.
                'material' => [
                    'id' => $material->id,
                    'name' => $material->name,
                    'color_hex' => $material->color_hex,
                    'texture_url' => $material->texture_url,
                    'thickness_mm' => $material->thickness_mm,
                ],
            ],
        ]);
    }

    /**
     * GET /api/quotes — histórico do usuário autenticado.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $quotes = Quote::query()
            ->with(['material:id,name,color_hex'])
            // Admin enxerga tudo; usuário comum, apenas o que é seu.
            ->unless($request->user()->isAdmin(), fn ($q) => $q->ownedBy($request->user()))
            // whereLike(caseSensitive: false) em vez do operador ILIKE: o ILIKE
            // é exclusivo do PostgreSQL e quebraria a suíte em qualquer outro
            // driver. O Laravel traduz para o operador nativo de cada banco.
            ->when($request->filled('client'), fn ($q) => $q->whereLike('client_name', "%{$request->string('client')}%", caseSensitive: false))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return QuoteResource::collection($quotes);
    }

    /**
     * POST /api/quotes — salva o orçamento no histórico.
     *
     * PONTO CRÍTICO DE SEGURANÇA: os valores são RECALCULADOS aqui a partir da
     * especificação. Se o payload trouxer `total_price`, ele é simplesmente
     * ignorado (nem consta no FormRequest). Não existe caminho pelo qual o
     * cliente defina o próprio preço.
     */
    public function store(StoreQuoteRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->calculateFrom($data, $material, $settings);

        $quote = DB::transaction(fn () => Quote::create([
            'user_id' => $request->user()->id,
            'material_id' => $material->id,
            'cost_setting_id' => $settings->id,

            'client_name' => $data['client_name'],
            'client_email' => $data['client_email'] ?? null,
            'client_document' => $data['client_document'] ?? null,
            'notes' => $data['notes'] ?? null,

            'width_mm' => (int) $data['width_mm'],
            'height_mm' => (int) $data['height_mm'],
            'depth_mm' => (int) $data['depth_mm'],
            'box_model' => $data['box_model'] ?? 'rsc',
            'quantity' => (int) ($data['quantity'] ?? 1),

            // Null = tampa automática. Guardado como o usuário informou (e não
            // como resolvido) para que o orçamento reabra no mesmo modo.
            'lid_width_mm' => $data['lid_width_mm'] ?? null,
            'lid_depth_mm' => $data['lid_depth_mm'] ?? null,
            'lid_height_mm' => $data['lid_height_mm'] ?? null,

            'waste_percent' => $data['waste_percent'] ?? $material->default_waste_percent,
            'production_minutes_per_unit' => $data['production_minutes_per_unit'] ?? 0,
            'profit_margin_percent' => $data['profit_margin_percent'] ?? $settings->default_profit_margin_percent,
            'pricing_mode' => $data['pricing_mode'] ?? 'markup',

            // Resultado materializado (as chaves de PricingResult casam com as colunas).
            ...collect($result->toArray())
                ->only([
                    'area_m2_per_unit', 'area_m2_total',
                    'material_cost', 'labor_cost', 'machine_cost', 'energy_cost',
                    'overhead_cost', 'unit_cost', 'unit_price',
                    'total_cost', 'total_price', 'profit_amount',
                ])
                ->all(),

            // Fotografia do contexto: torna o orçamento auditável mesmo depois
            // de o material encarecer ou a tarifa de energia mudar.
            'pricing_snapshot' => [
                'engine_version' => PricingEngine::VERSION,
                'calculated_at' => now()->toIso8601String(),
                'material' => $material->only(['id', 'name', 'cost_unit', 'cost_per_unit', 'grammage_kg_per_m2', 'thickness_mm']),
                'material_cost_per_m2' => $material->costPerSquareMeter(),
                'cost_settings' => $settings->only([
                    'energy_tariff_per_kwh', 'machine_hour_rate', 'machine_power_kw',
                    'labor_hour_rate', 'overhead_percent', 'tax_percent',
                ]),
                'breakdown' => $result->toArray(),
            ],

            'status' => 'draft',
        ]));

        return (new QuoteResource($quote->load('material')))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Quote $quote): QuoteResource
    {
        // Autorização por objeto via QuotePolicy: impede IDOR (trocar o id na
        // URL para ler o orçamento de outro usuário). Chamada explicitamente
        // em cada método porque authorizeResource() depende de
        // $this->middleware(), removido do Controller base no Laravel 11+.
        $this->authorize('view', $quote);

        return new QuoteResource($quote->load(['material', 'user:id,name']));
    }

    /**
     * PATCH /api/quotes/{quote} — apenas campos administrativos.
     *
     * Alterar dimensões ou material NÃO é editar: é outro orçamento. Isso
     * mantém a promessa de que um orçamento enviado ao cliente é imutável.
     */
    public function update(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote);

        $validated = $request->validate([
            'status' => ['sometimes', 'in:draft,sent,approved,rejected'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $quote->update($validated);

        return new QuoteResource($quote->fresh('material'));
    }

    public function destroy(Quote $quote): JsonResponse
    {
        $this->authorize('delete', $quote);

        $quote->delete(); // SoftDelete: o histórico é preservado.

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Monta a entrada e roda o motor.
     *
     * $material e $settings saem por referência porque o chamador precisa deles
     * para persistir e montar a resposta — evita duas consultas ao banco.
     *
     * @param  array<string, mixed>  $data
     */
    private function calculateFrom(array $data, ?Material &$material, ?CostSetting &$settings): PricingResult
    {
        $material = Material::active()->findOrFail($data['material_id']);
        $settings = CostSetting::current();

        return $this->pricing->calculate(
            PricingInput::fromValidated($data, $material, $settings)
        );
    }
}

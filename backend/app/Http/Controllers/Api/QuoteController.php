<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ComponentRole;
use App\Enums\CradleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimulateQuoteRequest;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Services\Pricing\CompanyHourCalculator;
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
        private readonly CompanyHourCalculator $companyHour,
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
        $result = $this->calculateFrom($data, $material, $settings, $bom);

        // Resolvidas uma vez e reaproveitadas nos dois destinos: as linhas de
        // `quote_custom_parts` e a fotografia dentro do snapshot.
        $customParts = $this->resolveCustomParts($data['custom_parts'] ?? []);

        $quote = DB::transaction(fn () => tap(Quote::create([
            'user_id' => $request->user()->id,
            'material_id' => $material->id,

            // Null fora da cartonagem rígida, e null também quando o usuário não
            // escolheu revestimento nenhum — caso em que o motor já cobra zero
            // por ele. Ver PricingEngine.
            'wrap_material_id' => $bom['wrap_material_id'],

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
                    'area_m2_per_unit', 'area_m2_total', 'wrap_area_m2_per_unit',
                    'material_cost', 'wrap_cost', 'hardware_cost',
                    'labor_cost', 'machine_cost', 'energy_cost',
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

                /*
                 * As peças CONGELADAS, e não só uma flag de "é modelo livre".
                 *
                 * Mesma razão do material e dos custos logo acima: se o usuário
                 * corrigir uma medida ou o preço do papelão amanhã, o orçamento
                 * que o cliente aprovou ontem não pode mudar de valor. As linhas
                 * em `quote_custom_parts` continuam editáveis; esta cópia é o
                 * que foi combinado.
                 */
                'custom_parts' => $customParts,
            ],

            'status' => 'draft',
        ]), function (Quote $quote) use ($customParts): void {
            /*
             * As peças viram linhas próprias além de entrarem no snapshot: é a
             * tabela que a ficha técnica consulta e que o usuário edita ao
             * duplicar o orçamento. O snapshot é fotografia, não fonte de
             * trabalho.
             */
            foreach ($customParts as $part) {
                $quote->customParts()->create([
                    'tenant_id' => $quote->tenant_id,
                    'material_id' => $part['material_id'],
                    'name' => $part['name'],
                    'component_role' => $part['role'],
                    'width_mm' => (int) $part['width_mm'],
                    'length_mm' => (int) $part['length_mm'],
                    'quantity' => $part['quantity'],
                ]);
            }
        }));

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
     * $material, $settings e $bom saem por referência porque o chamador precisa
     * deles para persistir e montar a resposta — evita repetir consultas ao
     * banco. O $bom entrou na lista quando o orçamento passou a gravar o id do
     * revestimento: resolvê-lo de novo em `store()` custaria uma segunda rodada
     * de buscas por material, e as duas poderiam divergir se alguém alterasse
     * uma delas.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $bom
     */
    private function calculateFrom(
        array $data,
        ?Material &$material,
        ?CostSetting &$settings,
        ?array &$bom = null,
    ): PricingResult {
        $material = Material::active()->findOrFail($data['material_id']);
        $settings = CostSetting::current();

        $bom = $this->resolveComponents($data['components'] ?? [], $data);

        /*
         * O custo do minuto é resolvido AQUI, e não dentro do motor: o
         * PricingEngine é puro e não toca no banco, e somar despesas fixas e
         * depreciação exige duas consultas. Devolve null com o modo desligado,
         * e nesse caso o motor calcula exatamente como sempre calculou.
         */
        return $this->pricing->calculate(
            PricingInput::fromValidated(
                $data,
                $material,
                $settings,
                $this->companyHour->minuteCostFor($settings),
                $bom['wrap_cost_per_m2'],
                $bom['hardware'],
                $bom['cradle'],
                $this->resolveCustomParts($data['custom_parts'] ?? []),
            )
        );
    }

    /**
     * Traduz as peças do modelo livre em números para o motor.
     *
     * Mesma regra de resolveComponents(): o motor recebe VALORES, nunca models.
     * `costPerSquareMeter()` e o percentual de perda são lidos AQUI, uma vez, no
     * servidor — o gêmeo em TypeScript recebe os dois prontos e não
     * reimplementa a conversão por gramatura.
     *
     * A perda sai do MATERIAL de cada peça, e não do orçamento. É a diferença
     * que importa no modelo livre: um mesmo orçamento mistura papelão cinza
     * (12%), kraft (8%) e tecido (15%), e um percentual único trataria os três
     * igual — subestimando justamente o que mais desperdiça.
     *
     * A busca é escopada pelo TenantScope: uma peça apontando para o material de
     * outra empresa não é encontrada, e o orçamento falha em vez de precificar
     * com o custo do vizinho.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return list<array{role: string, cost_per_m2: float, waste_percent: float, width_mm: float, length_mm: float, quantity: int}>
     */
    private function resolveCustomParts(array $parts): array
    {
        $resolvidas = [];

        foreach ($parts as $part) {
            $material = Material::active()->findOrFail($part['material_id']);

            $resolvidas[] = [
                'role' => $part['role'] ?? ComponentRole::Structure->value,
                'cost_per_m2' => $material->costPerSquareMeter(),
                'waste_percent' => (float) $material->default_waste_percent,
                'width_mm' => (float) $part['width_mm'],
                'length_mm' => (float) $part['length_mm'],
                'quantity' => (int) $part['quantity'],

                /*
                 * Descritivos, ignorados pelo motor. Viajam junto para não
                 * exigir uma segunda volta ao banco na hora de gravar as peças e
                 * de congelá-las no snapshot — e é o snapshot que a ficha
                 * técnica lê para dizer à produção o que cortar.
                 */
                'name' => (string) $part['name'],
                'material_id' => $material->id,
                'material_name' => $material->name,
            ];
        }

        return $resolvidas;
    }

    /**
     * Traduz a lista de materiais do payload em números para o motor.
     *
     * O motor recebe VALORES, não models: ele é puro, tem gêmeo em TypeScript e
     * não pode consultar banco. É aqui que `costPerSquareMeter()` (com a
     * conversão por gramatura) e `costPerPiece()` são chamados — uma vez, no
     * servidor, para que as duas pontas usem o mesmo número.
     *
     * A busca é escopada pelo TenantScope: um `material_id` de outra empresa
     * simplesmente não é encontrado, e o orçamento falha em vez de precificar
     * com o custo do vizinho.
     *
     * @param  list<array{material_id: int, role: string, quantity?: float|null}>  $components
     * @param  array<string, mixed>  $data  Payload validado, de onde saem os
     *                                      parâmetros de construção do berço.
     * @return array{wrap_cost_per_m2: ?float, hardware: list<array{cost_per_piece: float, quantity: float}>, cradle: ?array<string, mixed>}
     */
    private function resolveComponents(array $components, array $data = []): array
    {
        $wrapMaterial = null;
        $hardware = [];
        $cradleMaterial = null;

        foreach ($components as $component) {
            $material = Material::active()->findOrFail($component['material_id']);
            $role = ComponentRole::from($component['role']);

            match ($role) {
                /*
                 * O último revestimento vence, e não há erro se vierem dois.
                 * Uma peça tem UMA pele; receber duas é a interface trocando a
                 * seleção, não o usuário pedindo duas camadas — e derrubar a
                 * simulação por isso seria hostil no meio da digitação.
                 *
                 * Guarda o MODELO, e não só o custo, como já se fazia com o
                 * berço: o orçamento precisa gravar qual papel é, senão a ficha
                 * técnica não tem como saber a medida da folha dele.
                 */
                ComponentRole::Wrap => $wrapMaterial = $material,

                ComponentRole::Hardware => $hardware[] = [
                    'cost_per_piece' => $material->costPerPiece(),
                    // Sem quantidade explícita, uma peça: é o mínimo que faz
                    // sentido para quem acabou de arrastar um ímã para a lista.
                    'quantity' => (float) ($component['quantity'] ?? 1),
                ],

                /*
                 * Estrutura na lista é redundante — ela já é o `material_id` do
                 * orçamento. Ignorar em vez de recusar deixa o frontend enviar
                 * a lista COMPLETA (que é como ele a exibe) sem ter que
                 * filtrar a linha da estrutura antes de mandar.
                 */
                ComponentRole::Structure => null,

                ComponentRole::Cradle => $cradleMaterial = $material,
            };
        }

        return [
            'wrap_cost_per_m2' => $wrapMaterial?->costPerSquareMeter(),
            'wrap_material_id' => $wrapMaterial?->id,
            'hardware' => $hardware,
            'cradle' => $this->resolveCradle($cradleMaterial, $data),
        ];
    }

    /**
     * Monta a especificação do berço a partir do material e dos parâmetros.
     *
     * O TIPO decide qual conversão de custo usar, e é aí que a escolha errada
     * de material aparece: pedir berço de espuma com um papelão cotado em m²
     * lança DomainException com a mensagem do próprio Material, em vez de
     * multiplicar volume por preço de área e devolver um número sem sentido.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function resolveCradle(?Material $material, array $data): ?array
    {
        if ($material === null || ! isset($data['cradle_type'])) {
            return null;
        }

        $type = CradleType::from($data['cradle_type']);

        return [
            'type' => $type->value,

            'cost_per_unit' => $type->isVolumetric()
                ? $material->costPerCubicMeter()
                : $material->costPerSquareMeter(),

            'rows' => (int) ($data['cradle_rows'] ?? 1),
            'columns' => (int) ($data['cradle_columns'] ?? 1),
            'height_ratio' => (float) ($data['cradle_height_ratio'] ?? 1.0),

            /*
             * A espessura da tira sai do MATERIAL, não do formulário: quem
             * define a largura da ranhura é o papelão escolhido, e pedir o
             * número ao usuário abriria espaço para uma grade que não encaixa.
             */
            'strip_thickness_mm' => (float) ($material->thickness_mm ?? 0.0),
        ];
    }
}

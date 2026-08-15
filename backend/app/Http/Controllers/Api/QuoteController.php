<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ComponentRole;
use App\Enums\CradleType;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviseQuoteRequest;
use App\Http\Requests\SimulateQuoteRequest;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Client;
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

            /*
             * Filtro pelo cadastro, que é coisa diferente do filtro por nome
             * logo acima.
             *
             * `?client=silva` procura texto e acha qualquer coisa escrita
             * parecido; `?client_id=7` traz o histórico DAQUELE cliente, o que a
             * ficha dele precisa. Os dois convivem porque o orçamento pode ter
             * nascido sem cadastro — buscar por nome continua sendo o único
             * caminho para esses.
             */
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))

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
        $montado = $this->montaOrcamento($data);

        $quote = DB::transaction(fn () => tap(
            Quote::create([
                'user_id' => $request->user()->id,
                'status' => 'draft',
                ...$montado['atributos'],
            ]),
            fn (Quote $quote) => $this->gravaLinhas($quote, $montado),
        ));

        return (new QuoteResource($quote->load('material')))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * Roda o motor e devolve tudo que a gravação precisa, calculado uma vez.
     *
     * Existe porque `store()` e `revise()` precisam do MESMO resultado: um
     * orçamento reeditado tem de sair idêntico a um orçamento criado com a
     * mesma especificação. Duplicar noventa linhas entre os dois seria a
     * garantia de que a segunda cópia envelheceria sozinha — e a divergência
     * apareceria como dois preços diferentes para a mesma caixa.
     *
     * @param  array<string, mixed>  $data
     * @return array{atributos: array<string, mixed>, bom: array<string, mixed>, custom_parts: list<array<string, mixed>>}
     */
    private function montaOrcamento(array $data): array
    {
        $result = $this->calculateFrom($data, $material, $settings, $bom);

        // Resolvidas uma vez e reaproveitadas nos dois destinos: as linhas de
        // `quote_custom_parts` e a fotografia dentro do snapshot.
        $customParts = $this->resolveCustomParts($data['custom_parts'] ?? []);

        /*
         * O cliente cadastrado, resolvido pelo MODEL ESCOPADO.
         *
         * Mesmo cuidado — e mesma razão — de QuoteApprovalController: `exists`
         * na validação passaria por fora do TenantScope e gravaria o cliente da
         * empresa vizinha. O findOrFail escopado devolve 404.
         */
        $client = isset($data['client_id'])
            ? Client::query()->findOrFail($data['client_id'])
            : null;

        /*
         * As colunas do cliente só entram quando o payload as traz.
         *
         * A reedição usa ReviseQuoteRequest, que não aceita cliente: corrigir
         * uma medida não troca o destinatário. Ausentes daqui, o `update()`
         * simplesmente não as toca e o orçamento mantém quem sempre teve.
         */
        $doCliente = array_key_exists('client_name', $data) ? [
            'client_id' => $client?->id,

            /*
             * Os campos de texto são a FOTOGRAFIA do que foi combinado, e quando
             * há cadastro eles saem dele — não do que o navegador enviou.
             *
             * Os dois papéis convivem porque respondem a perguntas diferentes:
             * `client_id` liga o orçamento ao histórico e sobrevive a uma
             * correção de nome; `client_name` guarda o que estava escrito na
             * proposta. Sem o id, "Papelaria Silva" digitada três vezes vira
             * três clientes; sem o texto, renomear o cadastro reescreveria a
             * proposta que o cliente assinou.
             */
            'client_name' => $client?->name ?? $data['client_name'],
            'client_email' => $client?->email ?? ($data['client_email'] ?? null),
            'client_document' => $client?->cpf_cnpj ?? ($data['client_document'] ?? null),
        ] : [];

        return [
            'bom' => $bom,
            'custom_parts' => $customParts,
            'atributos' => [
                ...$doCliente,
                'material_id' => $material->id,

                // Null fora da cartonagem rígida, e null também quando o usuário não
                // escolheu revestimento nenhum — caso em que o motor já cobra zero
                // por ele. Ver PricingEngine.
                'wrap_material_id' => $bom['wrap_material_id'],

                /*
                 * Parâmetros de construção do berço, guardados como o usuário os
                 * informou. Sem eles um orçamento com berço reabria sem grade
                 * nenhuma — e a caixa que a produção montou não era a que a
                 * calculadora mostrou.
                 */
                'cradle_type' => $data['cradle_type'] ?? null,
                'cradle_rows' => $data['cradle_rows'] ?? null,
                'cradle_columns' => $data['cradle_columns'] ?? null,
                'cradle_height_ratio' => $data['cradle_height_ratio'] ?? null,

                'cost_setting_id' => $settings->id,

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
            ],
        ];
    }

    /**
     * Grava a lista de materiais e as peças, substituindo o que houver.
     *
     * O `delete()` antes do laço é o que torna a reedição possível: sem ele,
     * corrigir a quantidade de ímãs de 4 para 2 deixaria as duas linhas no
     * banco e a ficha técnica mandaria separar seis.
     *
     * @param  array{bom: array<string, mixed>, custom_parts: list<array<string, mixed>>}  $montado
     */
    private function gravaLinhas(Quote $quote, array $montado): void
    {
        $quote->components()->delete();
        $quote->customParts()->delete();

        /*
         * Ferragem e berço viram linhas próprias pela mesma razão das peças
         * logo abaixo: é o que a ficha técnica lê para dizer à produção o que
         * separar, e o que permite reabrir o orçamento depois. Antes disto
         * existia só `hardware_cost` — o número, sem os ímãs.
         */
        foreach ($montado['bom']['linhas'] as $linha) {
            $quote->components()->create([
                'tenant_id' => $quote->tenant_id,
                ...$linha,
            ]);
        }

        /*
         * As peças viram linhas próprias além de entrarem no snapshot: é a
         * tabela que a ficha técnica consulta e que o usuário edita ao duplicar
         * o orçamento. O snapshot é fotografia, não fonte de trabalho.
         */
        foreach ($montado['custom_parts'] as $part) {
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
    }

    public function show(Quote $quote): QuoteResource
    {
        // Autorização por objeto via QuotePolicy: impede IDOR (trocar o id na
        // URL para ler o orçamento de outro usuário). Chamada explicitamente
        // em cada método porque authorizeResource() depende de
        // $this->middleware(), removido do Controller base no Laravel 11+.
        $this->authorize('view', $quote);

        // `components` e `customParts` só aqui: é a tela do orçamento que
        // oferece duplicar e reeditar, e carregá-las na listagem seria payload
        // que ninguém lê.
        return new QuoteResource($quote->load([
            'material', 'user:id,name', 'components', 'customParts',
        ]));
    }

    /**
     * PATCH /api/quotes/{quote} — apenas campos administrativos.
     *
     * Alterar dimensões ou material NÃO é editar: é outro orçamento. Isso
     * mantém a promessa de que um orçamento enviado ao cliente é imutável.
     */
    public function update(Request $request, Quote $quote): QuoteResource|JsonResponse
    {
        $this->authorize('update', $quote);

        $validated = $request->validate([
            /*
             * `approved` NÃO entra na lista, e a ausência é a correção de um
             * furo: enquanto ele era aceito aqui, um PUT marcava o orçamento
             * como aprovado sem passar pelo QuoteApprovalController — ou seja,
             * sem lançar a venda no caixa e sem gerar parcela nenhuma. O
             * faturamento do mês ficava menor que as vendas fechadas, e nada na
             * tela denunciava.
             *
             * Aprovar tem endpoint próprio porque tem efeito colateral. Ver
             * QuoteStatus::Approved.
             */
            'status' => ['sometimes', 'in:draft,sent,rejected'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        /*
         * De aprovado não se volta.
         *
         * A aprovação lançou a venda e gerou as parcelas; devolver o orçamento
         * a rascunho deixaria o livro-caixa descrito por um documento que diz
         * não ter sido aprovado. Quem errou tem caminho: estornar a parcela e
         * excluir o lançamento, que são operações do financeiro e ficam
         * registradas — ao contrário de um status revertido em silêncio.
         */
        if ($quote->status === QuoteStatus::Approved && isset($validated['status'])) {
            return response()->json([
                'message' => 'Orçamento aprovado não muda de situação.',
                'errors' => ['status' => [
                    'A aprovação já lançou a venda no caixa. Para desfazer, estorne '
                    .'o lançamento no financeiro.',
                ]],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $quote->update($validated);

        return new QuoteResource($quote->fresh('material'));
    }

    /**
     * PUT /api/quotes/{quote}/specification — reeditar o RASCUNHO.
     *
     * A regra que `update()` registra continua valendo onde sempre valeu: um
     * orçamento ENVIADO ao cliente é imutável, e mexer nas medidas dele seria
     * outro orçamento. Rascunho é o caso que a regra nunca cobriu — ele não foi
     * enviado a ninguém, e obrigar quem errou uma medida a refazer tudo do zero
     * era uma punição sem beneficiário.
     *
     * O `pricing_snapshot` é REFEITO, e tem que ser: ele é a fotografia do que
     * foi calculado, e um rascunho reeditado tem um cálculo novo. Manter o
     * antigo faria a ficha técnica cortar a caixa velha.
     *
     * Endpoint próprio, e não um ramo dentro de `update()`: aquele aceita campo
     * solto e este exige a especificação inteira. Misturar os dois num método
     * que adivinha a intenção pelo payload é como um PUT sem `components`
     * acabaria apagando a ferragem em silêncio.
     */
    public function revise(ReviseQuoteRequest $request, Quote $quote): QuoteResource|JsonResponse
    {
        $this->authorize('update', $quote);

        if ($quote->status !== QuoteStatus::Draft) {
            return response()->json([
                'message' => 'Só rascunho pode ser reeditado.',
                'errors' => ['status' => [
                    'Este orçamento já saiu como proposta. Duplique-o para trabalhar '
                    .'sobre uma cópia — o que o cliente recebeu não muda de valor.',
                ]],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $montado = $this->montaOrcamento($request->validated());

        DB::transaction(function () use ($quote, $montado): void {
            // `user_id`, `reference` e `status` ficam de fora: quem criou
            // continua sendo quem criou, e a referência já foi para o papel.
            $quote->update($montado['atributos']);

            $this->gravaLinhas($quote, $montado);
        });

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

        /*
         * A lista a GRAVAR, montada junto com o cálculo.
         *
         * Separada do `$hardware` acima porque as duas têm públicos diferentes:
         * aquele alimenta o motor e carrega custo por peça já resolvido; este
         * alimenta `quote_components` e carrega identidade. Derivar um do outro
         * depois obrigaria a procurar o material de novo — e as duas buscas
         * poderiam divergir se alguém mexesse numa delas.
         */
        $linhas = [];

        foreach ($components as $component) {
            $material = Material::active()->findOrFail($component['material_id']);
            $role = ComponentRole::from($component['role']);

            // Sem quantidade explícita, uma peça: é o mínimo que faz sentido
            // para quem acabou de arrastar um ímã para a lista.
            $quantidade = (float) ($component['quantity'] ?? 1);

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
                    'quantity' => $quantidade,
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

            /*
             * O que vai para `quote_components` segue a MESMA regra do cálculo
             * logo acima: estrutura é redundante (já é `quotes.material_id`) e
             * revestimento tem coluna própria. Gravar os quatro papéis aqui
             * criaria duas fontes para os dois primeiros.
             */
            if (in_array($role, [ComponentRole::Hardware, ComponentRole::Cradle], true)) {
                $linhas[] = [
                    'material_id' => $material->id,
                    'component_role' => $role,

                    // Berço não se conta: a grade dele está nas colunas
                    // `cradle_*` do orçamento, não numa quantidade de peças.
                    'quantity' => $role === ComponentRole::Hardware ? $quantidade : null,
                ];
            }
        }

        return [
            'wrap_cost_per_m2' => $wrapMaterial?->costPerSquareMeter(),
            'wrap_material_id' => $wrapMaterial?->id,
            'hardware' => $hardware,
            'cradle' => $this->resolveCradle($cradleMaterial, $data),

            // A lista a gravar — ver `store()`. Fica fora de `hardware` porque
            // aquele é entrada do motor, e este é o que vira linha no banco.
            'linhas' => $linhas,
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

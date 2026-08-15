<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ComponentRole;
use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\Quote;
use App\Services\Production\CutTemplateCalculator;
use App\Services\Production\NestingCalculator;
use Illuminate\Http\JsonResponse;

/**
 * Ficha técnica de produção — o documento do chão de fábrica.
 *
 * O gabarito é calculado NO SERVIDOR e entregue pronto, e isso é deliberado:
 * duplicá-lo no Next.js criaria uma terceira implementação da geometria (PHP
 * do preço, TS do preview, TS da ficha) — e a paridade só vigia as duas
 * primeiras. A terceira divergiria em silêncio, e o silêncio apareceria como
 * papelão cortado errado.
 */
class TechnicalSheetController extends Controller
{
    public function __invoke(
        Quote $quote,
        CutTemplateCalculator $cutTemplate,
        NestingCalculator $nesting,
    ): JsonResponse {
        // Mesma política do orçamento: quem não pode ver a proposta não pode
        // ver a ficha, que carrega as mesmas medidas e o mesmo cliente.
        $this->authorize('view', $quote);

        // Os dois materiais da cartonagem rígida, numa consulta cada: o plano de
        // corte lê a folha dos dois, e sem isto o revestimento viria por lazy
        // load no meio do laço.
        $quote->loadMissing(['material', 'wrapMaterial', 'components.material']);

        $snapshot = $quote->pricing_snapshot ?? [];

        /*
         * A espessura sai do SNAPSHOT, não do material atual.
         *
         * Se o cadastro do papelão mudar de 1,9 para 3mm amanhã, a ficha de um
         * orçamento aprovado ontem tem que continuar mandando cortar a peça que
         * foi vendida. O snapshot é a fotografia do que foi combinado — usá-lo
         * aqui é o que mantém a produção fiel ao preço.
         */
        $thickness = (float) ($snapshot['material']['thickness_mm'] ?? 0.0);

        $gabarito = $cutTemplate->forQuote(
            model: $quote->box_model,
            widthMm: (float) $quote->width_mm,
            heightMm: (float) $quote->height_mm,
            depthMm: (float) $quote->depth_mm,
            thicknessMm: $thickness,
            lidMm: $quote->lid_width_mm !== null ? [
                'width' => (float) $quote->lid_width_mm,
                'depth' => (float) $quote->lid_depth_mm,
                'height' => (float) $quote->lid_height_mm,
            ] : null,

            /*
             * As peças do modelo livre saem do SNAPSHOT pela mesma razão que a
             * espessura: as linhas em `quote_custom_parts` continuam editáveis,
             * e a produção precisa cortar o que foi VENDIDO, não o que alguém
             * ajustou depois de a proposta ter saído.
             */
            customParts: $snapshot['custom_parts'] ?? [],
        );

        return response()->json([
            'data' => [
                'quote' => [
                    'id' => $quote->id,
                    'reference' => $quote->reference,
                    'client_name' => $quote->client_name,
                    'status' => $quote->status->value,
                    'created_at' => $quote->created_at?->toIso8601String(),
                    'notes' => $quote->notes,
                ],

                'specification' => [
                    'box_model' => $quote->box_model->value,
                    'box_model_label' => $quote->box_model->label(),
                    'width_mm' => (float) $quote->width_mm,
                    'height_mm' => (float) $quote->height_mm,
                    'depth_mm' => (float) $quote->depth_mm,
                    'thickness_mm' => $thickness,
                    'quantity' => $quote->quantity,
                    'material' => $snapshot['material']['name'] ?? $quote->material?->name,
                ],

                'cut_template' => $gabarito,

                /*
                 * Lista de separação: o que sair do estoque para o LOTE, não
                 * para uma peça. Quem vai ao estoque leva o pedido inteiro, e
                 * multiplicar mentalmente por 250 na frente da prateleira é
                 * como se erra a retirada.
                 */
                'picking_list' => $this->pickingList($quote, $gabarito),

                /*
                 * Plano de corte: a perda REAL, medida no arranjo das peças na
                 * folha, ao lado da perda ORÇADA. É a diferença entre os dois
                 * que interessa — "você orçou 12%, o corte dá 23%".
                 *
                 * Informativo por decisão: o preço continua saindo do percentual
                 * cadastrado. Nesting é heurística, e um preço que depende de
                 * heurística muda sem nenhuma entrada ter mudado.
                 */
                'cutting_plan' => $this->cuttingPlan($quote, $gabarito, $snapshot, $nesting),
            ],
        ]);
    }

    /**
     * Agrupa as peças por MATERIAL e roda o nesting em cada grupo.
     *
     * Por material e não por orçamento porque cada um tem folha própria: uma
     * caixa rígida usa papelão cinza e papel de revestimento, comprados em
     * formatos diferentes. Um plano único com uma medida só descreveria uma
     * chapa que não existe no estoque.
     *
     * @param  array<string, mixed>  $gabarito
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function cuttingPlan(
        Quote $quote,
        array $gabarito,
        array $snapshot,
        NestingCalculator $nesting,
    ): array {
        $grupos = $quote->box_model->isFree()
            ? $this->gruposDoModeloLivre($snapshot)
            : $this->gruposComGeometria($quote, $gabarito);

        $planos = [];
        $avisos = [];

        /*
         * Caixa que PEDE revestimento e foi orçada sem escolher nenhum.
         *
         * O aviso vale mais pelo que diz do PREÇO do que do plano: sem material
         * de revestimento o motor cobra zero por ele (ver PricingEngine), então
         * a proposta saiu com o papel de graça. Quem lê a ficha na bancada é
         * quem descobre — e é melhor descobrir aqui do que no fim do mês.
         *
         * Não vale para orçamentos antigos apenas: `wrap_material_id` nasceu
         * nula em tudo que já existia, e a mensagem serve igualmente bem para
         * eles — o papel realmente não foi cobrado.
         */
        if (! $quote->box_model->isFree()
            && ($gabarito['wrap'] ?? []) !== []
            && $quote->wrapMaterial === null
        ) {
            $avisos[] = 'Este orçamento não tem revestimento escolhido: o papel não entra no plano '
                .'de corte e também não foi cobrado no preço. Refaça o orçamento escolhendo o '
                .'material de revestimento.';
        }

        foreach ($grupos as $grupo) {
            $material = $grupo['material'];

            /*
             * Sem a medida da folha não há plano de corte — e o aviso é mais
             * útil que a ausência: ele diz exatamente qual cadastro completar
             * para o número aparecer.
             */
            if (! $material?->sheet_width_mm || ! $material->sheet_length_mm) {
                $avisos[] = sprintf(
                    'Cadastre a medida da folha de "%s" para ver o plano de corte deste material.',
                    $material?->name ?? 'material não identificado',
                );

                continue;
            }

            // Peças do LOTE, não de uma caixa: quem vai cortar corta o pedido
            // inteiro, e a pergunta é quantas folhas comprar.
            $pecas = array_map(fn (array $p): array => [
                'name' => $p['name'],
                'width_mm' => $p['width_mm'],
                'length_mm' => $p['length_mm'],
                'quantity' => $p['quantity'] * $quote->quantity,
            ], $grupo['parts']);

            try {
                $plano = $nesting->plan(
                    parts: $pecas,
                    sheetWidthMm: (float) $material->sheet_width_mm,
                    sheetLengthMm: (float) $material->sheet_length_mm,
                    allowRotation: $material->grain_direction->permiteRotacao(),
                );
            } catch (\DomainException $e) {
                // Peça maior que a folha: cadastro incoerente, não erro de
                // programação. Vira aviso na ficha em vez de derrubá-la.
                $avisos[] = $e->getMessage();

                continue;
            }

            $perdaOrcada = (float) $material->default_waste_percent;

            $planos[] = [
                'material' => ['id' => $material->id, 'name' => $material->name],
                'sheet' => [
                    'width_mm' => (float) $material->sheet_width_mm,
                    'length_mm' => (float) $material->sheet_length_mm,
                ],
                'kerf_mm' => NestingCalculator::DEFAULT_KERF_MM,
                'grain_direction' => $material->grain_direction->value,
                'rotation_allowed' => $material->grain_direction->permiteRotacao(),

                'quoted_waste_percent' => $perdaOrcada,
                'real_waste_percent' => $plano['waste_percent'],

                /*
                 * A linha que paga o módulo inteiro. Positiva significa que a
                 * empresa está perdendo mais chapa do que cobra — e o valor sai
                 * do lucro sem aparecer em lugar nenhum.
                 */
                'divergence_percent' => round($plano['waste_percent'] - $perdaOrcada, 2),

                ...$plano,
            ];
        }

        return [
            'by_material' => $planos,
            'warnings' => $avisos,
            'notes' => [
                'A perda real é medida no arranjo das peças na folha, com a lâmina '
                    .'de '.NestingCalculator::DEFAULT_KERF_MM.'mm descontada a cada corte.',
                'Os cortes são guilhotinados: atravessam a folha de ponta a ponta, '
                    .'como na guilhotina da bancada.',

                /*
                 * O limite do método, dito na cara. Um plano de corte que se
                 * apresenta como ótimo e não é faz a produção confiar num número
                 * que a bancada vai desmentir.
                 */
                'O arranjo é uma boa solução, não a melhor possível: quem corta '
                    .'pode conseguir mais peças por folha.',
                'Este plano NÃO altera o preço do orçamento — ele mostra se a perda '
                    .'cadastrada está próxima da real.',
            ],
        ];
    }

    /**
     * Modelo livre: cada peça aponta para o próprio material.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array{material: ?Material, parts: list<array<string, mixed>>}>
     */
    private function gruposDoModeloLivre(array $snapshot): array
    {
        $porMaterial = [];

        foreach ($snapshot['custom_parts'] ?? [] as $part) {
            $id = $part['material_id'] ?? null;

            if ($id === null) {
                continue;
            }

            $porMaterial[$id][] = [
                'name' => $part['name'] ?? 'Peça',
                'width_mm' => (float) $part['width_mm'],
                'length_mm' => (float) $part['length_mm'],
                'quantity' => (int) $part['quantity'],
            ];
        }

        // Uma consulta para todos os materiais — o N+1 morava aqui.
        $materiais = Material::query()->findMany(array_keys($porMaterial))->keyBy('id');

        $grupos = [];

        foreach ($porMaterial as $id => $parts) {
            $grupos[] = ['material' => $materiais->get($id), 'parts' => $parts];
        }

        return $grupos;
    }

    /**
     * Modelos com geometria: duas camadas, dois materiais, dois planos.
     *
     * A cartonagem rígida corta em DUAS chapas diferentes — o papelão cinza da
     * estrutura e o papel do revestimento —, e por muito tempo só a primeira
     * aparecia aqui. O motivo era de banco: o orçamento gravava o custo do
     * revestimento e não o id do material, então não havia como descobrir a
     * medida da folha dele. Sem folha, não há plano.
     *
     * A coluna `wrap_material_id` fechou esse buraco, e o que ela destrava é
     * justamente o material mais caro da caixa: revestimento passa de R$ 20/m²
     * onde o cinza fica em R$ 5. É nele que a perda de canto dói.
     *
     * @param  array<string, mixed>  $gabarito
     * @return list<array{material: ?Material, parts: list<array<string, mixed>>}>
     */
    private function gruposComGeometria(Quote $quote, array $gabarito): array
    {
        $grupos = [];

        $estrutura = $this->comoPecasDeCorte($gabarito['structure'] ?? []);

        if ($estrutura !== []) {
            $grupos[] = ['material' => $quote->material, 'parts' => $estrutura];
        }

        $revestimento = $this->comoPecasDeCorte($gabarito['wrap'] ?? []);

        if ($revestimento !== [] && $quote->wrapMaterial !== null) {
            $grupos[] = ['material' => $quote->wrapMaterial, 'parts' => $revestimento];
        }

        return $grupos;
    }

    /**
     * Traduz peças do gabarito para o vocabulário do nesting.
     *
     * O gabarito chama de `height_mm` o que o nesting chama de comprimento: são
     * o mesmo eixo da peça deitada na folha.
     *
     * @param  list<array<string, mixed>>  $pecas
     * @return list<array{name: string, width_mm: float, length_mm: float, quantity: int}>
     */
    private function comoPecasDeCorte(array $pecas): array
    {
        return array_map(fn (array $p): array => [
            'name' => (string) $p['name'],
            'width_mm' => (float) $p['width_mm'],
            'length_mm' => (float) $p['height_mm'],
            'quantity' => (int) $p['quantity'],
        ], $pecas);
    }

    /**
     * Consolida as peças pelo tamanho do lote.
     *
     * @param  array<string, mixed>  $gabarito
     * @return list<array<string, mixed>>
     */
    private function pickingList(Quote $quote, array $gabarito): array
    {
        $linhas = [];

        foreach (['structure' => 'Papelão cinza', 'wrap' => 'Revestimento'] as $papel => $rotulo) {
            foreach ($gabarito[$papel] as $peca) {
                $linhas[] = [
                    'material_role' => $papel,
                    'material_label' => $rotulo,
                    'piece' => $peca['name'],
                    'size' => sprintf('%.1f × %.1f mm', $peca['width_mm'], $peca['height_mm']),
                    'per_unit' => $peca['quantity'],
                    'total' => $peca['quantity'] * $quote->quantity,
                ];
            }
        }

        /*
         * Ferragem e berço — o que se compra e não se corta.
         *
         * Estavam FORA desta lista, e a ausência era cara: a produção recebia a
         * folha do papelão e ia comprar ímã de memória. O gabarito não os
         * mostra porque eles não têm planificação; a lista de separação mostra,
         * porque ela responde "o que sai do estoque", e ímã sai.
         */
        foreach ($quote->components as $componente) {
            $papel = $componente->component_role;

            $linhas[] = [
                'material_role' => $papel->value,
                'material_label' => $papel->label(),
                'piece' => $componente->material?->name ?? 'Material removido',

                // O berço não se conta em peças: o que descreve o tamanho dele
                // é a grade, e é ela que a bancada precisa ler.
                'size' => $papel === ComponentRole::Cradle
                    ? $this->descreveBerco($quote)
                    : 'unidade',

                /*
                 * Um berço por caixa. A quantidade da ferragem vem do cadastro
                 * do orçamento (quatro ímãs), e a fração existe porque fita de
                 * cetim é comprada por peça e consumida em metro e meio.
                 */
                'per_unit' => $componente->quantity ?? 1.0,
                'total' => ($componente->quantity ?? 1.0) * $quote->quantity,
            ];
        }

        return $linhas;
    }

    /** "Espuma · grade 3 × 4 · 65% da altura" — o berço como a bancada o monta. */
    private function descreveBerco(Quote $quote): string
    {
        $partes = array_filter([
            $quote->cradle_type?->label(),

            $quote->cradle_rows !== null && $quote->cradle_columns !== null
                ? sprintf('grade %d × %d', $quote->cradle_rows, $quote->cradle_columns)
                : null,

            $quote->cradle_height_ratio !== null
                ? sprintf('%d%% da altura', (int) round($quote->cradle_height_ratio * 100))
                : null,
        ]);

        return $partes === [] ? 'sem parâmetros' : implode(' · ', $partes);
    }
}

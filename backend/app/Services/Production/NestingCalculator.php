<?php

declare(strict_types=1);

namespace App\Services\Production;

/**
 * Plano de corte 2D — quantas peças cabem numa folha, e quanto sobra de verdade.
 *
 * O que ele responde, e que o sistema até agora só chutava: a perda REAL. Hoje
 * o preço usa `default_waste_percent`, um número digitado uma vez no cadastro do
 * material — 12% no papelão, 8% no kraft. O plano de corte mostra que naquela
 * chapa, com aquelas peças, sobram 23%. A diferença é o prejuízo que ninguém
 * enxergava.
 *
 * INFORMATIVO, NÃO AUTORITATIVO. Ele não entra no preço, e essa fronteira é
 * deliberada: nesting é heurística, e uma heurística que define preço faz o
 * valor mudar sem nenhuma entrada ter mudado — basta reordenar as peças. Por
 * isso também não tem gêmeo em TypeScript nem caso de paridade: o motor de
 * preço continua sendo a única coisa espelhada.
 *
 * O algoritmo é guilhotinável (todo corte atravessa a chapa de ponta a ponta),
 * porque é o que uma guilhotina de cartonagem faz — um empacotamento ótimo que
 * exigisse recortes internos descreveria uma máquina que a empresa não tem.
 */
final class NestingCalculator
{
    /** Espessura da lâmina, em mm. Cada corte come material. */
    public const DEFAULT_KERF_MM = 1.5;

    /**
     * Tetos de esforço.
     *
     * O modelo livre aceita 60 peças de até 500 unidades: 30.000 retângulos. Sem
     * teto, o laço percorre a árvore de TODAS as chapas abertas a cada peça, e
     * com peças pequenas numa chapa grande as chapas chegam aos milhares — dezenas
     * de milhões de visitas a nó numa requisição HTTP. Estourado o limite, o
     * serviço devolve estimativa em vez de plano, e diz que fez isso.
     */
    private const MAX_PIECES = 4000;

    private const MAX_SHEETS = 400;

    /**
     * Distribui as peças em folhas do tamanho informado.
     *
     * @param  list<array{name: string, width_mm: float, length_mm: float, quantity: int}>  $parts
     * @return array{
     *     sheets_needed: int,
     *     efficiency_percent: float,
     *     waste_percent: float,
     *     truncated: bool,
     *     layouts: list<array<string, mixed>>
     * }
     */
    public function plan(
        array $parts,
        float $sheetWidthMm,
        float $sheetLengthMm,
        bool $allowRotation,
        float $kerfMm = self::DEFAULT_KERF_MM,
    ): array {
        $pecas = $this->expandir($parts);
        $totalDePecas = count($pecas);

        $truncado = $totalDePecas > self::MAX_PIECES;

        if ($truncado) {
            $pecas = array_slice($pecas, 0, self::MAX_PIECES);
        }

        /** @var list<GuillotineNode> $raizes */
        $raizes = [];

        /** @var list<array{sheet_id: int, parts: list<array<string, mixed>>, used_area: float}> $folhas */
        $folhas = [];

        $colocadas = 0;

        foreach ($pecas as $peca) {
            $encaixe = null;
            $indice = null;

            // Primeira folha em que couber (first-fit decreasing).
            foreach ($raizes as $i => $raiz) {
                $encaixe = $this->encaixar($raiz, $peca['w'], $peca['l'], $allowRotation, $kerfMm);

                if ($encaixe !== null) {
                    $indice = $i;
                    break;
                }
            }

            if ($encaixe === null) {
                if (count($raizes) >= self::MAX_SHEETS) {
                    $truncado = true;
                    break;
                }

                $raiz = new GuillotineNode(0.0, 0.0, $sheetWidthMm, $sheetLengthMm);
                $encaixe = $this->encaixar($raiz, $peca['w'], $peca['l'], $allowRotation, $kerfMm);

                if ($encaixe === null) {
                    /*
                     * A peça não cabe nem numa folha vazia. Não é erro de
                     * programação — é cadastro incoerente, e o usuário precisa
                     * ver qual peça e qual folha para corrigir uma das duas.
                     */
                    throw new \DomainException(sprintf(
                        'A peça "%s" (%.0f×%.0f mm) não cabe na folha de %.0f×%.0f mm cadastrada '
                        .'para este material. Revise a medida da peça ou a da folha.',
                        $peca['name'], $peca['w'], $peca['l'], $sheetWidthMm, $sheetLengthMm,
                    ));
                }

                $raizes[] = $raiz;
                $indice = count($raizes) - 1;
                $folhas[$indice] = ['sheet_id' => $indice + 1, 'parts' => [], 'used_area' => 0.0];
            }

            $folhas[$indice]['parts'][] = [
                'name' => $peca['name'],
                'x' => round($encaixe['x'], 1),
                'y' => round($encaixe['y'], 1),
                'width_mm' => round($encaixe['w'], 1),
                'length_mm' => round($encaixe['l'], 1),
                'rotated' => $encaixe['rotated'],
            ];

            $folhas[$indice]['used_area'] += $peca['w'] * $peca['l'];
            $colocadas++;
        }

        return $this->consolidar(
            array_values($folhas), $sheetWidthMm, $sheetLengthMm,
            $truncado, $colocadas, $totalDePecas,
        );
    }

    /**
     * Explode as quantidades e ordena por área decrescente.
     *
     * O desempate é EXPLÍCITO e total — área, largura, comprimento, nome. Ordenar
     * só por área deixaria duas peças de mesma área em ordem indefinida, e a
     * ordem decide o layout, que decide a perda: o mesmo orçamento produziria
     * relatórios diferentes em execuções diferentes. Um número que muda sozinho
     * não serve para conferir nada.
     *
     * @param  list<array{name: string, width_mm: float, length_mm: float, quantity: int}>  $parts
     * @return list<array{name: string, w: float, l: float}>
     */
    private function expandir(array $parts): array
    {
        $pecas = [];

        foreach ($parts as $part) {
            $quantidade = max((int) ($part['quantity'] ?? 1), 0);

            for ($i = 0; $i < $quantidade; $i++) {
                $pecas[] = [
                    'name' => (string) $part['name'],
                    'w' => (float) $part['width_mm'],
                    'l' => (float) $part['length_mm'],
                ];
            }
        }

        usort($pecas, function (array $a, array $b): int {
            return [$b['w'] * $b['l'], $b['w'], $b['l'], $a['name']]
                <=> [$a['w'] * $a['l'], $a['w'], $a['l'], $b['name']];
        });

        return $pecas;
    }

    /**
     * Tenta encaixar a peça, com e sem rotação.
     *
     * @return array{x: float, y: float, w: float, l: float, rotated: bool}|null
     */
    private function encaixar(
        GuillotineNode $raiz,
        float $w,
        float $l,
        bool $allowRotation,
        float $kerf,
    ): ?array {
        $no = $raiz->insert($w, $l, $kerf);

        if ($no !== null) {
            return ['x' => $no->x, 'y' => $no->y, 'w' => $w, 'l' => $l, 'rotated' => false];
        }

        /*
         * Girar 90° só quando o material permite. Papelão e papel têm fibra: a
         * peça cortada atravessado empena depois de colada, e o defeito aparece
         * dias depois, com a caixa já entregue. Ver GrainDirection.
         */
        if ($allowRotation && $w !== $l) {
            $no = $raiz->insert($l, $w, $kerf);

            if ($no !== null) {
                return ['x' => $no->x, 'y' => $no->y, 'w' => $l, 'l' => $w, 'rotated' => true];
            }
        }

        return null;
    }

    /**
     * @param  list<array{sheet_id: int, parts: list<array<string, mixed>>, used_area: float}>  $folhas
     * @return array<string, mixed>
     */
    private function consolidar(
        array $folhas,
        float $w,
        float $l,
        bool $truncado,
        int $colocadas,
        int $totalDePecas,
    ): array {
        $areaDaFolha = $w * $l;

        $layouts = [];
        $areaUsada = 0.0;

        foreach ($folhas as $folha) {
            $areaUsada += $folha['used_area'];

            $layouts[] = [
                'sheet_id' => $folha['sheet_id'],
                'width_mm' => $w,
                'length_mm' => $l,
                'parts' => $folha['parts'],

                // Aproveitamento DESTA folha. A última costuma ser a pior, e é
                // ela que denuncia se compensa juntar dois pedidos numa tiragem.
                'efficiency_percent' => $areaDaFolha > 0
                    ? round($folha['used_area'] / $areaDaFolha * 100, 2)
                    : 0.0,
            ];
        }

        $areaTotal = $areaDaFolha * count($layouts);

        $aproveitamento = $areaTotal > 0 ? round($areaUsada / $areaTotal * 100, 2) : 0.0;

        return [
            'sheets_needed' => count($layouts),
            'efficiency_percent' => $aproveitamento,

            /*
             * A perda REAL: tudo que a folha tem e a peça não usou. Inclui as
             * sobras de canto que nenhum percentual de cadastro representa, e é
             * o número que se compara com o `default_waste_percent` orçado.
             */
            'waste_percent' => round(100 - $aproveitamento, 2),

            'layouts' => $layouts,

            'truncated' => $truncado,
            'pieces_planned' => $colocadas,
            'pieces_total' => $totalDePecas,

            /*
             * Folhas estimadas para o pedido INTEIRO quando o plano foi
             * truncado.
             *
             * A extrapolação é honesta porque o aproveitamento se estabiliza: as
             * primeiras centenas de peças já revelam a perda do arranjo, e o
             * resto repete o padrão. O que ela não captura é a última folha, que
             * é sempre a pior — por isso o número é `estimated` e não
             * `needed`, e a diferença está no nome para quem lê não confundir.
             */
            'sheets_estimated' => $truncado && $colocadas > 0
                ? (int) ceil(count($layouts) * $totalDePecas / $colocadas)
                : count($layouts),
        ];
    }
}

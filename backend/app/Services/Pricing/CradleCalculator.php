<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\CradleType;

/**
 * Consumo de material dos berços internos.
 *
 * ⚠️  Espelha cradleConsumption() em frontend/lib/pricing/engine.ts.
 *
 * Puro como o BlankCalculator: recebe medidas em MILÍMETROS e devolve área em
 * m² ou volume em m³, sem tocar em banco. As dimensões que entram são as
 * INTERNAS da caixa — o berço veste o vão útil, e é por isso que ele encolhe
 * quando o papelão engorda.
 */
final class CradleCalculator
{
    private const MM2_PER_M2 = 1_000_000.0;

    private const MM3_PER_M3 = 1_000_000_000.0;

    /**
     * Folga do berço contra as paredes, em mm.
     *
     * Um berço cortado na medida exata do vão não entra: o revestimento das
     * paredes rouba décimos, e a espuma comprime mas não desliza. 1mm por lado
     * é o que a produção usa para encaixar sem forçar.
     */
    private const CRADLE_CLEARANCE_MM = 1.0;

    /** Espessura da tira de divisória quando o material não a informa, em mm. */
    private const DEFAULT_STRIP_THICKNESS_MM = 1.9;

    /**
     * Sobra de corte das tiras, em mm por tira.
     *
     * Cada tira é guilhotinada individualmente e ranhurada. A ranhura em si não
     * consome material (é um rasgo), mas o refile das pontas consome — e numa
     * grade 4×5 são nove tiras, onde a sobra deixa de ser desprezível.
     */
    private const STRIP_TRIM_MM = 4.0;

    /**
     * O que um berço consome, na grandeza que o tipo dele cobra.
     *
     * @param  int  $rows  Linhas da grade (só DividerGrid).
     * @param  int  $columns  Colunas da grade (só DividerGrid).
     * @param  float  $heightRatio  Altura do berço em fração da altura interna.
     *                              1.0 = até a boca da caixa.
     * @return array{area_m2: float, volume_m3: float, strips: int}
     */
    public function consumption(
        CradleType $type,
        float $widthMm,
        float $heightMm,
        float $depthMm,
        int $rows = 1,
        int $columns = 1,
        float $heightRatio = 1.0,
        float $stripThicknessMm = 0.0,
    ): array {
        // O berço vive DENTRO do vão útil, com folga para entrar.
        $w = max($widthMm - 2 * self::CRADLE_CLEARANCE_MM, 0.0);
        $d = max($depthMm - 2 * self::CRADLE_CLEARANCE_MM, 0.0);
        $h = max($heightMm * $heightRatio, 0.0);

        return match ($type) {
            /*
             * Espuma: o bloco inteiro. O nicho recortado NÃO é descontado — o
             * vazio sai de um bloco que já foi comprado, e o miolo removido é
             * sobra, não economia. Descontá-lo faria o berço mais elaborado
             * (mais recortes) sair mais barato que o simples, que é o oposto da
             * realidade da produção.
             */
            CradleType::Foam => [
                'area_m2' => 0.0,
                'volume_m3' => ($w * $d * $h) / self::MM3_PER_M3,
                'strips' => 0,
            ],

            /*
             * Nichos de cartonagem: fundo + as quatro paredes internas, como
             * uma bandeja rasa que entra na caixa. É a mesma cruz do berço da
             * caixa livro, em escala reduzida.
             */
            CradleType::BoardNiche => [
                'area_m2' => (($w + 2 * $h) * ($d + 2 * $h)) / self::MM2_PER_M2,
                'volume_m3' => 0.0,
                'strips' => 0,
            ],

            /*
             * Nichos de papel: base de cartonagem MAIS a colmeia de papel
             * cartão por cima. Consome mais área que o nicho rígido apesar de
             * ser mais barato — o papel é fino, e a colmeia tem muitas paredes.
             */
            CradleType::PaperNiche => [
                'area_m2' => (($w * $d) + 2 * ($w + $d) * $h) / self::MM2_PER_M2,
                'volume_m3' => 0.0,
                'strips' => 0,
            ],

            /*
             * Papel dobrado: uma peça só, a cruz clássica. O berço mais leve —
             * e o único que o próprio cliente consegue refazer se amassar.
             */
            CradleType::PaperFold => [
                'area_m2' => (($w + 2 * $h) * ($d + 2 * $h)) / self::MM2_PER_M2,
                'volume_m3' => 0.0,
                'strips' => 0,
            ],

            CradleType::DividerGrid => $this->dividerGrid($w, $d, $h, $rows, $columns, $stripThicknessMm),
        };
    }

    /**
     * Grade de divisórias encaixadas.
     *
     * Uma grade de R linhas por C colunas precisa de (R−1) tiras transversais e
     * (C−1) longitudinais — as bordas são as próprias paredes da caixa. Uma
     * grade 1×1 é "sem divisória" e consome zero, que é o resultado certo para
     * quem selecionou a opção e não configurou nada.
     *
     * A ranhura fêmea-fêmea não desconta área: é um rasgo de meia altura, e o
     * material que sai dele é apara. O que se paga por tira é o REFILE das
     * pontas, que a guilhotina exige.
     *
     * @return array{area_m2: float, volume_m3: float, strips: int}
     */
    private function dividerGrid(
        float $w,
        float $d,
        float $h,
        int $rows,
        int $columns,
        float $stripThicknessMm,
    ): array {
        $transversais = max($rows - 1, 0);
        $longitudinais = max($columns - 1, 0);

        if ($transversais + $longitudinais === 0) {
            return ['area_m2' => 0.0, 'volume_m3' => 0.0, 'strips' => 0];
        }

        $espessura = $stripThicknessMm > 0 ? $stripThicknessMm : self::DEFAULT_STRIP_THICKNESS_MM;

        /*
         * Cada tira transversal atravessa a LARGURA; cada longitudinal, a
         * profundidade. Elas perdem a espessura das tiras que cruzam — sem
         * isso a grade não fecha no vão e as tiras estufam as paredes.
         */
        $comprimentoTransversal = max($w - $longitudinais * $espessura, 0.0) + self::STRIP_TRIM_MM;
        $comprimentoLongitudinal = max($d - $transversais * $espessura, 0.0) + self::STRIP_TRIM_MM;

        $area = $transversais * ($comprimentoTransversal * $h)
            + $longitudinais * ($comprimentoLongitudinal * $h);

        return [
            'area_m2' => $area / self::MM2_PER_M2,
            'volume_m3' => 0.0,
            'strips' => $transversais + $longitudinais,
        ];
    }
}

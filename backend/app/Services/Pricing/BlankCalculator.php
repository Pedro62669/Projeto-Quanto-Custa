<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\BoxModel;

/**
 * Converte as dimensões INTERNAS da embalagem na área de material necessária
 * para produzi-la — o "blank" (plano de corte planificado).
 *
 * Por que não "soma das 6 faces": uma caixa não é um cubo oco recortado em
 * seis pedaços. Ela é uma chapa única, dobrada, com abas de colagem e abas de
 * fechamento que se sobrepõem. Somar faces subestima o consumo em 15–30% num
 * RSC — o suficiente para o orçamento sair no prejuízo. Aqui usamos a
 * planificação real de cada modelo.
 *
 * Todas as medidas de entrada e intermediárias estão em MILÍMETROS; a saída
 * é em METROS QUADRADOS.
 */
final class BlankCalculator
{
    /** Aba de colagem lateral do RSC/luva, em mm. */
    private const GLUE_FLAP_MM = 35.0;

    /** Folga entre base e tampa para que encaixem, em mm. */
    private const LID_CLEARANCE_MM = 2.0;

    /** Altura da tampa como fração da altura da base. */
    private const LID_HEIGHT_RATIO = 0.35;

    /** Margem de selagem de sacos/envelopes, em mm. */
    private const SEAL_MM = 10.0;

    private const MM2_PER_M2 = 1_000_000.0;

    /**
     * @param  float  $thicknessMm  Espessura do material. Cada dobra "consome"
     *                              cerca de uma espessura em comprimento; para
     *                              papelão ondulado (3–7mm) ignorar isso gera
     *                              caixas que não fecham.
     */
    public function __construct(
        private readonly float $thicknessMm = 0.0,
    ) {}

    /**
     * Área líquida do plano de corte, em m² (ainda SEM desperdício).
     */
    public function areaInSquareMeters(
        BoxModel $model,
        float $widthMm,
        float $heightMm,
        float $depthMm,
    ): float {
        $blank = $this->blankDimensions($model, $widthMm, $heightMm, $depthMm);

        return ($blank['width'] * $blank['height']) / self::MM2_PER_M2;
    }

    /**
     * Dimensões do retângulo de material a ser cortado.
     *
     * Exposto separadamente porque a UI mostra "sua caixa consome uma chapa de
     * 820 × 540 mm" — informação que o operador usa para escolher o formato
     * da bobina/chapa.
     *
     * O parâmetro $lidMm carrega as medidas efetivas da tampa (ver
     * resolveLidDimensions). Null usa a sugestão — o consumo de material
     * acompanha a tampa REAL, e não a padrão, senão uma tampa mais alta
     * sairia de graça.
     *
     * @param  array{width: float, depth: float, height: float}|null  $lidMm
     * @return array{width: float, height: float}
     */
    public function blankDimensions(
        BoxModel $model,
        float $widthMm,
        float $heightMm,
        float $depthMm,
        ?array $lidMm = null,
    ): array {
        // Compensação de dobra: cada vinco perde ~1 espessura.
        $t = $this->thicknessMm;

        return match ($model) {
            // RSC: chapa única contornando as 4 laterais + abas de topo/fundo.
            //   largura = 2×(frente + lateral) + aba de colagem
            //   altura  = altura + (2 × meia-lateral), pois as abas se
            //             encontram no centro => somam uma profundidade inteira
            BoxModel::Rsc => [
                'width' => 2 * ($widthMm + $depthMm) + self::GLUE_FLAP_MM + 4 * $t,
                'height' => $heightMm + $depthMm + 2 * $t,
            ],

            // Bandeja com tampa: dois blanks somados no mesmo retângulo
            // equivalente (base empilhada sobre a tampa no plano de corte).
            BoxModel::Tray => $this->trayBlank(
                $widthMm,
                $heightMm,
                $depthMm,
                $t,
                $lidMm ?? $this->defaultLidDimensions($model, $widthMm, $heightMm, $depthMm),
            ),

            // Luva: cinta fechada ao redor do produto, sem topo nem fundo.
            BoxModel::Sleeve => [
                'width' => 2 * ($widthMm + $depthMm) + self::GLUE_FLAP_MM + 4 * $t,
                'height' => $heightMm,
            ],

            // Saco/envelope com fole: frente + verso + fundo sanfonado + selagem.
            BoxModel::Pouch => [
                'width' => $widthMm + 2 * self::SEAL_MM,
                'height' => 2 * $heightMm + $depthMm + 2 * self::SEAL_MM,
            ],
        };
    }

    /**
     * Dimensões FÍSICAS SUGERIDAS da tampa (não do plano de corte), em mm.
     *
     * Existe separado de blankDimensions() porque atende outra pergunta: o
     * blank responde "quanto material comprar", isto responde "que tamanho
     * tem a peça". A UI mostra estas medidas ao usuário e o renderizador 3D
     * as usa para desenhar a tampa — ambos a partir desta única definição,
     * em vez de repetirem as constantes de folga e altura.
     *
     * É uma SUGESTÃO: o usuário pode informar as próprias medidas, e nesse
     * caso quem manda é resolveLidDimensions().
     *
     * Devolve null para modelos que não têm tampa separada.
     *
     * @return array{width: float, depth: float, height: float}|null
     */
    public function defaultLidDimensions(
        BoxModel $model,
        float $widthMm,
        float $heightMm,
        float $depthMm,
    ): ?array {
        if ($model !== BoxModel::Tray) {
            return null;
        }

        $t = $this->thicknessMm;

        return [
            // A tampa encaixa POR FORA da base: precisa vencer a folga de
            // encaixe e ainda a espessura das paredes dela própria.
            'width' => $widthMm + 2 * self::LID_CLEARANCE_MM + 2 * $t,
            'depth' => $depthMm + 2 * self::LID_CLEARANCE_MM + 2 * $t,
            'height' => $heightMm * self::LID_HEIGHT_RATIO,
        ];
    }

    /**
     * Medidas efetivas da tampa: o que o usuário informou, ou a sugestão.
     *
     * Cada eixo é resolvido de forma independente — dá para fixar só a altura
     * da tampa e deixar largura e profundidade acompanhando a base.
     *
     * @param  array{width: ?float, depth: ?float, height: ?float}  $overrides
     * @return array{width: float, depth: float, height: float}|null
     */
    public function resolveLidDimensions(
        BoxModel $model,
        float $widthMm,
        float $heightMm,
        float $depthMm,
        array $overrides = ['width' => null, 'depth' => null, 'height' => null],
    ): ?array {
        $default = $this->defaultLidDimensions($model, $widthMm, $heightMm, $depthMm);

        if ($default === null) {
            return null;
        }

        return [
            'width' => $overrides['width'] ?? $default['width'],
            'depth' => $overrides['depth'] ?? $default['depth'],
            'height' => $overrides['height'] ?? $default['height'],
        ];
    }

    /**
     * Bandeja = base (com paredes dobradas para cima) + tampa telescópica.
     *
     * Como são duas peças, devolvemos um retângulo equivalente cuja ÁREA
     * corresponde à soma das duas — mantendo a largura da peça maior para que
     * o número exibido ainda faça sentido como largura de chapa.
     *
     * O blank da tampa é obtido rebatendo as saias a partir das medidas
     * FÍSICAS dela: cada lado cresce duas alturas de tampa.
     *
     * @param  array{width: float, depth: float, height: float}  $lid
     * @return array{width: float, height: float}
     */
    private function trayBlank(float $w, float $h, float $d, float $t, array $lid): array
    {
        // Base: fundo + 4 paredes rebatidas.
        $baseW = $w + 2 * $h + 2 * $t;
        $baseH = $d + 2 * $h + 2 * $t;

        // Tampa: planificação das medidas físicas informadas (ou sugeridas).
        $lidW = $lid['width'] + 2 * $lid['height'];
        $lidH = $lid['depth'] + 2 * $lid['height'];

        $totalArea = ($baseW * $baseH) + ($lidW * $lidH);
        $width = max($baseW, $lidW);

        return [
            'width' => $width,
            'height' => $totalArea / $width,
        ];
    }
}

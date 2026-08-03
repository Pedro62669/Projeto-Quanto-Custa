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

    /**
     * Aba que trava a lateral rolada da mailer no fundo, em mm.
     *
     * É uma aba de TRAVA, não de colagem: por isso é bem menor que a
     * GLUE_FLAP_MM e não escala com a caixa — quem segura a parede é o
     * rolo, ela só impede que ele se desenrole.
     */
    private const ROLL_TAB_MM = 20.0;

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

            // Caixa gaveta: luva externa + gaveta interna, duas peças.
            BoxModel::Drawer => $this->drawerBlank($widthMm, $heightMm, $depthMm, $t),

            // Mailer box: peça única die-cut, tampa articulada e laterais roladas.
            BoxModel::Mailer => $this->mailerBlank($widthMm, $heightMm, $depthMm, $t),

            // Tubo cilíndrico: a largura é o DIÂMETRO; a profundidade é ignorada.
            BoxModel::Tube => $this->tubeBlank(
                $widthMm,
                $heightMm,
                $t,
                $lidMm ?? $this->defaultLidDimensions($model, $widthMm, $heightMm, $depthMm),
            ),
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
        if (! $model->hasSeparateLid()) {
            return null;
        }

        $t = $this->thicknessMm;

        // A tampa encaixa POR FORA da base: precisa vencer a folga de encaixe
        // e ainda a espessura das paredes dela própria.
        $folga = 2 * self::LID_CLEARANCE_MM + 2 * $t;

        // Num cilindro largura e profundidade são o mesmo diâmetro; devolver
        // valores diferentes produziria uma tampa oval.
        $depthBase = $model->isCylindrical() ? $widthMm : $depthMm;

        return [
            'width' => $widthMm + $folga,
            'depth' => $depthBase + $folga,
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
     * Caixa gaveta = gaveta interna + luva externa.
     *
     * As dimensões informadas são as INTERNAS da gaveta (o espaço útil). A
     * luva não é dimensionada pela caixa "por fora" de forma aproximada: ela
     * precisa envolver a gaveta JÁ MONTADA, ou seja, vencer as paredes dela
     * (duas espessuras em cada eixo) mais a folga de deslize. Sem isso a
     * gaveta não entra — e o orçamento sairia de uma peça que não encaixa.
     *
     * A gaveta é uma bandeja: fundo com as quatro paredes rebatidas. A luva é
     * uma cinta fechada, aberta nas duas pontas, cujo comprimento é a
     * profundidade da caixa (o eixo do deslize).
     *
     * @return array{width: float, height: float}
     */
    private function drawerBlank(float $w, float $h, float $d, float $t): array
    {
        // Gaveta: fundo + 4 paredes rebatidas.
        $gavetaW = $w + 2 * $h + 2 * $t;
        $gavetaH = $d + 2 * $h + 2 * $t;

        // Luva: seção interna = gaveta montada + folga de deslize.
        $secaoLargura = $w + 2 * $t + self::LID_CLEARANCE_MM;
        $secaoAltura = $h + 2 * $t + self::LID_CLEARANCE_MM;

        $luvaW = 2 * ($secaoLargura + $secaoAltura) + self::GLUE_FLAP_MM;
        $luvaH = $d;

        $totalArea = $gavetaW * $gavetaH + $luvaW * $luvaH;
        $width = max($gavetaW, $luvaW);

        return [
            'width' => $width,
            'height' => $totalArea / $width,
        ];
    }

    /**
     * Mailer box (RETT) = peça única die-cut, sem cola.
     *
     * A planificação, a partir do fundo:
     *  - no eixo da profundidade: parede frontal, fundo, parede traseira,
     *    tampa articulada e lingueta de fechamento — cinco painéis em linha;
     *  - no eixo da largura: as abas ROLADAS presas ao fundo, que sobem (h),
     *    dobram 180° no topo, descem por dentro (h) e travam com uma aba
     *    curta sobre o fundo.
     *
     * Duas decisões que mudam o número:
     *
     * 1. A lateral custa DOBRADO. O rolo é o que dá a rigidez e o canto liso
     *    da mailer, e cobrar uma parede simples (como num RSC) subestimaria a
     *    peça justamente onde ela gasta mais. É a razão de uma mailer sair
     *    ~20% acima de um RSC de mesma medida — e essa diferença é real.
     *
     * 2. Somamos a ÁREA LÍQUIDA dos painéis, não o retângulo que os envolve.
     *    Contrário do que fazemos com os discos do tubo: um círculo dentro de
     *    um quadrado é perda irredutível, mas os recortes entre a tampa e as
     *    abas roladas são encaixáveis — blanks de mailer aninham bem no
     *    layout de corte. Cobrar o retângulo envolvente somaria ~35% que o
     *    percentual de desperdício já cobre, contando a mesma perda duas vezes.
     *
     * @return array{width: float, height: float}
     */
    private function mailerBlank(float $w, float $h, float $d, float $t): array
    {
        // Comprimento da aba lateral rolada: sobe, volta por dentro e trava.
        // A dobra de 180° no topo consome mais que um vinco comum — daí 4t.
        $rolo = 2 * $h + self::ROLL_TAB_MM + 4 * $t;

        // Orelhas das paredes frontal/traseira, presas ENTRE as duas camadas
        // do rolo (é o que trava a caixa sem cola). Nunca mais fundas que a
        // própria caixa, senão colidiriam com a parede oposta.
        $orelha = min($h, $d);

        $area =
            // Espinha: fundo + tampa articulada (dois painéis w × d)…
            2 * ($w * $d)
            // …+ paredes frontal e traseira + lingueta de fechamento.
            + 3 * ($w * $h)
            // Laterais roladas, uma de cada lado, correndo ao longo da caixa.
            + 2 * ($rolo * $d)
            // Quatro orelhas quadradas nas pontas das paredes frontal/traseira.
            + 4 * ($orelha ** 2)
            // Abas laterais da tampa, que descem por dentro ao fechar.
            + 2 * ($h * $d);

        // Largura real da chapa: a caixa mais as duas abas roladas abertas.
        $width = $w + 2 * $rolo;

        return [
            'width' => $width,
            'height' => $area / $width,
        ];
    }

    /**
     * Tubo cilíndrico = corpo enrolado + disco de fundo + tampa com saia.
     *
     * Duas decisões de engenharia importantes aqui:
     *
     * 1. O corpo é planificado pela circunferência da LINHA MÉDIA da parede
     *    (π × (D + espessura)), e não pelo diâmetro interno. Enrolar uma chapa
     *    faz a face externa percorrer um caminho maior que a interna; usar o
     *    diâmetro interno subestima o comprimento e o tubo não fecha. Em
     *    papelão de 7mm num tubo de 100mm, a diferença passa de 20mm.
     *
     * 2. Os discos são orçados pelo QUADRADO que os circunscreve, não pela
     *    área do círculo. Recortar um disco de uma chapa descarta os cantos —
     *    e esse descarte é consumo real de matéria-prima, não desperdício de
     *    processo. Cobrar só π·r² faria o fundo e a tampa saírem 21% baratos
     *    demais. Quem aninha os discos para aproveitar melhor pode compensar
     *    reduzindo o percentual de desperdício.
     *
     * @param  array{width: float, depth: float, height: float}|null  $lid
     * @return array{width: float, height: float}
     */
    private function tubeBlank(float $diameterMm, float $h, float $t, ?array $lid): array
    {
        // Corpo: retângulo cujo comprimento é a circunferência média.
        $bodyW = M_PI * ($diameterMm + $t) + self::GLUE_FLAP_MM;
        $bodyH = $h;

        // Fundo: disco recortado de um quadrado de lado igual ao diâmetro.
        $bottomArea = $diameterMm ** 2;

        $totalArea = $bodyW * $bodyH + $bottomArea;
        $widest = max($bodyW, $diameterMm);

        if ($lid !== null) {
            // Tampa: saia enrolada (mesma lógica de circunferência) + disco.
            $lidSkirtW = M_PI * ($lid['width'] + $t) + self::GLUE_FLAP_MM;

            $totalArea += $lidSkirtW * $lid['height'] + $lid['width'] ** 2;
            $widest = max($widest, $lidSkirtW, $lid['width']);
        }

        return [
            'width' => $widest,
            'height' => $totalArea / $widest,
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

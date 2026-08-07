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

    /*
     * As razões da faca da mailer.
     *
     * ⚠️  Espelham as constantes MAILER_* de frontend/lib/pricing/engine.ts, de
     * onde o renderizador 3D também as lê. Uma mailer não tem medidas de tampa
     * no formulário: estas frações SÃO o modelo. Alterar aqui sem alterar lá
     * faz o preview desenhar uma peça e o orçamento cobrar outra.
     *
     * Todas saem de mailer/box-mailer.blend, a peça que o cliente modelou — as
     * medidas foram tiradas do glTF exportado, painel a painel, e NÃO do
     * mailer.py ao lado dele. A distinção custou caro para aparecer: o script
     * no disco é uma versão anterior do modelo, e nele a língua tem 70 onde a
     * peça real tem 84,5 e a barbatana 20×28 onde a real tem 31,8×38,8. Quem
     * for conferir, meça o .blend (ou o .glb), nunca o .py.
     *
     * Lá as medidas são fixas, porque o modelo é UMA caixa (300×250, parede
     * 81,5, papelão 3); aqui viram razões, senão a caixa não redimensiona —
     * numa 100×300×100 as abas de 60mm se atravessam no meio do fundo.
     */

    /** Avanço das abas laterais das paredes, em fração da profundidade (60/250). */
    private const MAILER_TAB_RATIO = 0.24;

    /**
     * Abas laterais da tampa, em fração da altura de parede (70/81,5).
     *
     * Descem por DENTRO, encostando na parede interna do rolo, e por isso param
     * antes do piso. A língua não usa esta razão: ela desce por fora e é
     * medida à parte (parede inteira mais uma espessura).
     */
    private const MAILER_LID_FLAP_RATIO = 0.86;

    /**
     * Trecho da tampa que a aba lateral ocupa, em fração do comprimento dela.
     *
     * Medido no modelo: a aba vai de 5 a 209 numa tampa de 253. Começa depois
     * da parede traseira e PARA ANTES da barbatana — as duas descem no mesmo
     * plano, e sobrepô-las é o que impede a trava de fechar.
     */
    private const MAILER_LID_FLAP_START_RATIO = 0.02;

    private const MAILER_LID_FLAP_END_RATIO = 0.826;

    /**
     * Lingueta que prende o rolo ao fundo, em fração da altura e da largura.
     *
     * Vale o MENOR dos dois: precisa caber na fenda (que fica a uma lingueta da
     * borda do fundo) e não pode passar do alcance da parede interna. Na caixa
     * do script as duas dão 18.
     */
    private const MAILER_LOCK_HEIGHT_RATIO = 0.22;

    private const MAILER_LOCK_WIDTH_RATIO = 0.06;

    /** Fendas do fundo: centro e comprimento, em fração da profundidade. */
    private const MAILER_SLOT_CENTER_RATIO = 0.29;

    private const MAILER_SLOT_LENGTH_RATIO = 0.22;

    /**
     * Avanço da barbatana, em fração do avanço da aba da parede (31,8/60).
     *
     * É a profundidade que ela entra no BOLSO — o vão entre a aba da parede
     * frontal e a parede interna do rolo. Quem limita é a aba: passar dela é
     * sair pelo outro lado do bolso, e a trava deixa de existir.
     */
    private const MAILER_FIN_OUT_RATIO = 0.53;

    /** Faixa que a barbatana ocupa ao longo da língua (38,8/84,5). */
    private const MAILER_FIN_BAND_RATIO = 0.459;

    /** Chanfro da ponta dianteira da aba da tampa, em fração da queda DELA (18 e 25 de 70). */
    private const MAILER_LID_FLAP_CHAMFER_X = 0.26;

    private const MAILER_LID_FLAP_CHAMFER_Y = 0.36;

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

            // Mailer box: peça única die-cut, tampa articulada, sem cola.
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
     * Mailer box (RETT) = uma chapa só, cortada em faca e dobrada sem cola.
     *
     * A soma abaixo percorre painel a painel a MESMA decomposição que o
     * renderizador 3D desenha (MailerMesh). Esse é o critério escolhido para
     * este modelo: o que aparece na tela é o que entra na conta. Um painel
     * que exista no preço e não no desenho — ou o contrário — é uma
     * divergência que nenhum teste deste projeto enxerga, porque a paridade
     * só compara PHP com TS, nunca o preço com a figura.
     *
     * A decisão que domina o custo é o ROLO. A lateral sobe, dobra 180° no
     * topo (a ponte) e desce por dentro até o piso, onde linguetas prendem em
     * fendas do fundo: são TRÊS painéis de altura por lateral, contra um do
     * RSC. É o que torna a mailer cara em caixas altas, e há teste fixando
     * essa assinatura.
     *
     * Recortes internos — fendas, entalhe de dedo, chanfros — não são
     * descontados da área: são aparas já cobertas pelo percentual de
     * desperdício.
     *
     * @return array{width: float, height: float}
     */
    private function mailerBlank(float $w, float $h, float $d, float $t): array
    {
        $l = $this->mailerLayout($w, $h, $d, $t);

        $area =
            // Fundo, com as quatro fendas como recorte interno.
            $l['w'] * $l['d']
            // Paredes frontal e traseira. A frontal é mais estreita: ela recua
            // para dar passagem à barbatana, que desce no plano da tampa.
            + 2 * $l['xWallFront'] * $l['hw']
            + 2 * $l['xTabHinge'] * $l['hw']
            // Abas laterais das duas paredes, alojadas no bolso do rolo.
            + 4 * ($l['tab'] * $l['hw'])
            // Painel da tampa, do vinco traseiro até além da parede frontal.
            + 2 * $l['xLid'] * $l['lid']
            // Abas laterais da tampa, com a ponta da frente chanfrada para
            // liberar o bolso onde a barbatana entra.
            + 2 * (($l['lidFlapEnd'] - $l['lidFlapStart']) * $l['lidFlap']
                - ($l['chamferX'] * $l['chamferY']) / 2)
            // Língua frontal, que desce por FORA da parede.
            + 2 * $l['xLid'] * $l['frontFlap']
            // As duas barbatanas, contadas pelo retângulo que as envolve: o
            // contorno é bézier, e a área exata exigiria o mesmo shoelace nos
            // dois motores para a paridade fechar. A apara da curva está dentro
            // do retângulo que a faca precisa liberar de qualquer jeito.
            + 2 * ($l['finOut'] * $l['finBand'])
            // O rolo: externa, ponte e interna.
            + 2 * ($l['d'] * $l['hw'])
            + 2 * ($l['d'] * $l['bridge'])
            + 2 * (2 * $l['yInner'] * $l['hw'])
            // As quatro linguetas que prendem o rolo ao fundo.
            + 4 * (($l['slotEnd'] - $l['slotStart']) * $l['lock']);

        // Largura real da chapa, direto do faca_mm do script: metade da caixa
        // mais a coluna inteira do rolo (externa + ponte + interna + lingueta).
        $width = $l['w'] + 2 * ($l['hw'] + $l['bridge'] + $l['hw'] + $l['lock']);

        return [
            'width' => $width,
            'height' => $area / $width,
        ];
    }

    /**
     * Layout da faca da mailer, em mm — planos, painéis e abas.
     *
     * ⚠️  Espelha mailerLayout() em frontend/lib/pricing/engine.ts, de onde o
     * renderizador 3D lê os MESMOS números para montar a peça. Não são só "as
     * abas": é o desenho inteiro, porque qualquer plano que o desenho
     * recalculasse por conta própria seria uma segunda decomposição, e a
     * paridade automática compara PHP com TS, nunca o preço com a figura.
     *
     * As medidas digitadas são as INTERNAS, e o script trabalha em planos de
     * dobra: a conversão soma a folga que cada camada rouba do vão livre.
     *
     *   largura: 5t — as duas paredes internas do rolo, duas espessuras e meia
     *            de cada lado (a ponte mais a própria camada)
     *   profundidade: t — meia espessura da parede frontal e meia da traseira
     *   altura: t/2 — meia espessura do piso, já que a tampa pousa por cima
     *
     * Somar em vez de subtrair não é detalhe de sinal: é o que mantém a mailer
     * coerente com o resto do motor, onde papelão mais grosso SEMPRE pede blank
     * maior (o RSC soma 4t, a bandeja 2t). Tratando o digitado como externo, a
     * caixa encolhia por dentro e o preço caía junto — papelão grosso saindo
     * mais barato que fino, o que nenhum convertedor aceitaria ver na tela.
     *
     * @return array<string, float>
     */
    private function mailerLayout(float $w, float $h, float $d, float $t): array
    {
        $ww = $w + 5 * $t;
        $dd = $d + $t;
        $hw = $h + $t / 2;

        $bridge = 2 * $t;

        // Os quatro planos verticais, cada um recuado do anterior por uma
        // espessura: aba da parede traseira, parede interna do rolo, tampa,
        // parede frontal. Esse escalonamento é o que faz cada peça caber
        // DENTRO da anterior ao fechar.
        $xTabHinge = max($ww / 2 - $t, 0.0);
        $xInner = max($ww / 2 - $bridge, 0.0);
        $xLid = max($ww / 2 - $bridge - $t, 0.0);
        $xWallFront = max($xLid - $t, 0.0);

        $tab = min(self::MAILER_TAB_RATIO * $dd, max($dd / 2 - $t, 0.0));
        $lidFlap = min(self::MAILER_LID_FLAP_RATIO * $hw, max($hw - $t, 0.0));
        $finOut = min(self::MAILER_FIN_OUT_RATIO * $tab, max($tab - $t, 0.0));

        // A língua cobre a parede inteira MAIS uma espessura — ela desce por
        // fora e precisa passar da borda do fundo. O teto é a própria tampa.
        $frontFlap = min($hw + $t, $dd);

        // A tampa vai do vinco traseiro até uma espessura além da parede
        // frontal — é o que põe a língua do lado de FORA dela.
        $lid = $dd + $t;

        $slotHalf = (self::MAILER_SLOT_LENGTH_RATIO * $dd) / 2;
        $slotCenter = min(
            self::MAILER_SLOT_CENTER_RATIO * $dd,
            max($dd / 2 - $t - $slotHalf, 0.0),
        );

        return [
            't' => $t,
            'w' => $ww,
            'd' => $dd,
            'hw' => $hw,
            'bridge' => $bridge,
            'xTabHinge' => $xTabHinge,
            'xInner' => $xInner,
            'xLid' => $xLid,
            'xWallFront' => $xWallFront,
            'yInner' => max($dd / 2 - $t / 2, 0.0),
            'lid' => $lid,
            'tab' => $tab,
            'frontFlap' => $frontFlap,
            'lidFlap' => $lidFlap,
            'lidFlapStart' => self::MAILER_LID_FLAP_START_RATIO * $lid,
            'lidFlapEnd' => self::MAILER_LID_FLAP_END_RATIO * $lid,
            'chamferX' => self::MAILER_LID_FLAP_CHAMFER_X * $lidFlap,
            'chamferY' => self::MAILER_LID_FLAP_CHAMFER_Y * $lidFlap,
            'lock' => min(
                self::MAILER_LOCK_HEIGHT_RATIO * $hw,
                self::MAILER_LOCK_WIDTH_RATIO * $ww,
                $xInner,
            ),
            'slotStart' => max($slotCenter - $slotHalf, 0.0),
            'slotEnd' => $slotCenter + $slotHalf,
            'finOut' => $finOut,
            'finBand' => min(self::MAILER_FIN_BAND_RATIO * $frontFlap, max($frontFlap - $t, 0.0)),
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

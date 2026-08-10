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
     * Virada do revestimento sobre as bordas, em mm — cartonagem rígida.
     *
     * ⚠️  Espelha TURN_IN_MM em frontend/lib/pricing/engine.ts.
     *
     * O papel não para na quina: dobra por cima dela e cola no lado de dentro.
     * 15mm é a virada de ofício — o suficiente para o papel não descolar e
     * pouco o bastante para não aparecer no vão da tampa. Ela entra DUAS vezes
     * por eixo (uma borda de cada lado), e é a razão de o revestimento sempre
     * consumir mais chapa que o cinza que ele cobre.
     */
    private const TURN_IN_MM = 15.0;

    /**
     * Sobra de esquadrejo do papelão cinza, em mm.
     *
     * O cinza não vinca: cada painel é cortado separado e colado em esquadro.
     * A guilhotina precisa de pega, e o corte final refila as bordas — sem esta
     * folga o cálculo assume aproveitamento perfeito de chapa, que não existe
     * em nenhuma cartonagem.
     */
    private const RIGID_TRIM_MM = 8.0;

    /**
     * Canaleta entre os painéis da capa, em múltiplos da espessura.
     *
     * ⚠️  Espelha HINGE_GAP_RATIO em frontend/lib/pricing/engine.ts.
     *
     * É a fenda que permite a capa dobrar. Vazia de propósito: quem une os
     * painéis é o papel de revestimento, que atravessa o vão e vira a
     * dobradiça. 1,5 espessura é a razão de ofício — menos que isso e o papel
     * rasga na primeira abertura, mais e a capa fica frouxa.
     *
     * Consequência que decide as duas áreas deste modelo: a canaleta NÃO
     * consome papelão (é vazio), mas consome revestimento (que a cobre).
     */
    private const HINGE_GAP_RATIO = 1.5;

    /**
     * Quanto a aba envolvente avança sob o fundo, em fração da profundidade.
     *
     * ⚠️  Espelha MAGNET_WRAP_UNDER_RATIO em frontend/lib/pricing/engine.ts.
     *
     * Um quarto da profundidade: o suficiente para a aba prender sob a caixa e
     * dar a superfície contínua que o modelo vende, sem chegar ao meio do fundo
     * (onde ela se encontraria com a contracapa e criaria uma quarta camada de
     * cinza sob a peça, que balança quando apoiada).
     */
    private const MAGNET_WRAP_UNDER_RATIO = 0.25;

    /**
     * Pad de assentamento do ímã na ponta da aba, em mm.
     *
     * ⚠️  Espelha MAGNET_PAD_MM em frontend/lib/pricing/engine.ts.
     *
     * A aba do fecho magnético não para na altura da parede: ela avança sobre a
     * FACE FRONTAL para que o ímã dela encontre o par embutido no berço. Esses
     * 12mm são a área de encontro — sem eles os ímãs ficariam na quina, onde a
     * força de atração é mínima e a caixa abre sozinha.
     *
     * É também o que separa esta aba da aba de fechamento do livro, que apenas
     * cobre a parede e se recolhe por atrito. Uma medida em MILÍMETROS, e não
     * em múltiplos de espessura, porque o ímã tem tamanho próprio: um papelão
     * mais fino não faz o ímã encolher.
     */
    private const MAGNET_PAD_MM = 12.0;

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

            // Cartonagem rígida: o blank aqui é o do PAPELÃO CINZA. O
            // revestimento tem área própria — ver wrapAreaInSquareMeters().
            BoxModel::RigidTelescopic => $this->rigidTelescopicBlank(
                $widthMm,
                $heightMm,
                $depthMm,
                $t,
                $lidMm ?? $this->defaultLidDimensions($model, $widthMm, $heightMm, $depthMm),
            ),

            // Família da capa rígida: livro e ímã compartilham a construção.
            BoxModel::RigidBook,
            BoxModel::RigidBookFlap,
            BoxModel::RigidMagnet,
            BoxModel::RigidMagnetSide,
            BoxModel::RigidMagnetWrap => $this->bookBlank($model, $widthMm, $heightMm, $depthMm, $t),

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
     * Área do REVESTIMENTO, em m² — só nos modelos de cartonagem rígida.
     *
     * Devolve 0.0 nos demais: numa caixa dobrada o material é um só, e o papel
     * impresso (quando existe) já está laminado na chapa que o blank mede.
     *
     * A conta é a mesma planificação do cinza acrescida da virada em todas as
     * bordas — mais a espessura, porque o papel percorre a lateral do painel
     * antes de dobrar. É a área que mais custa na peça: revestimento bom passa
     * de R$ 20/m² onde o cinza fica em R$ 5.
     */
    public function wrapAreaInSquareMeters(
        BoxModel $model,
        float $widthMm,
        float $heightMm,
        float $depthMm,
        ?array $lidMm = null,
    ): float {
        if (! $model->isRigid()) {
            return 0.0;
        }

        $t = $this->thicknessMm;

        if ($model->isBook()) {
            return $this->bookWrapArea($model, $widthMm, $heightMm, $depthMm, $t)
                / self::MM2_PER_M2;
        }

        $lid = $lidMm ?? $this->defaultLidDimensions($model, $widthMm, $heightMm, $depthMm);

        $area = $this->rigidWrapPanel($widthMm, $heightMm, $depthMm, $t)
            + $this->rigidWrapPanel($lid['width'], $lid['height'], $lid['depth'], $t);

        return $area / self::MM2_PER_M2;
    }

    /**
     * Caixa tampa solta rígida = base + tampa, ambas em papelão cinza.
     *
     * Cada peça é uma cruz: o fundo com as quatro paredes ao redor. Diferente
     * da bandeja dobrada, aqui a cruz não é vincada — é o desenho de corte de
     * cinco painéis que serão colados em esquadro. Para o consumo de chapa dá
     * no mesmo: é o retângulo que a guilhotina precisa liberar, e os quatro
     * cantos vazios são apara já coberta pelo desperdício.
     *
     * As paredes entram DUAS vezes por eixo (uma de cada lado), que é o
     * "+ 2 × altura" da fórmula clássica. A espessura entra porque o painel do
     * fundo fica ENTRE as paredes, e não sob elas: cada parede empurra a
     * medida externa em uma espessura.
     *
     * @param  array{width: float, depth: float, height: float}  $lid
     * @return array{width: float, height: float}
     */
    private function rigidTelescopicBlank(float $w, float $h, float $d, float $t, array $lid): array
    {
        $baseW = $w + 2 * $h + 2 * $t + self::RIGID_TRIM_MM;
        $baseH = $d + 2 * $h + 2 * $t + self::RIGID_TRIM_MM;

        $lidW = $lid['width'] + 2 * $lid['height'] + 2 * $t + self::RIGID_TRIM_MM;
        $lidH = $lid['depth'] + 2 * $lid['height'] + 2 * $t + self::RIGID_TRIM_MM;

        // As duas peças saem da mesma chapa: soma das áreas, largura da maior.
        // Mesma convenção da bandeja e da gaveta — o retângulo equivalente.
        $totalArea = ($baseW * $baseH) + ($lidW * $lidH);
        $width = max($baseW, $lidW);

        return [
            'width' => $width,
            'height' => $totalArea / $width,
        ];
    }

    /**
     * Geometria da caixa livro, em mm — a fonte única dos dois blanks e do 3D.
     *
     * ⚠️  Espelha bookLayout() em frontend/lib/pricing/engine.ts, de onde o
     * renderizador lê os MESMOS números para desenhar a peça.
     *
     * As medidas informadas são as INTERNAS do berço (o espaço útil). Tudo o
     * mais é derivado: o berço veste as paredes, a capa veste o berço, e a
     * lombada precisa vencer a altura do berço MAIS as duas capas que ela une —
     * é o erro clássico do modelo, uma lombada curta deixa a caixa arqueada e
     * sem fechar.
     *
     * @return array<string, float>
     */
    public function bookLayout(BoxModel $model, float $w, float $h, float $d, float $t): array
    {
        // Berço montado: as paredes empurram a medida externa em uma espessura
        // de cada lado; o fundo empurra a altura em uma.
        $bercoW = $w + 2 * $t;
        $bercoD = $d + 2 * $t;
        $bercoH = $h + $t;

        // A capa veste o berço com folga, senão ele não entra e a tampa não
        // encosta. Mesma folga de encaixe da tampa telescópica.
        $capaW = $bercoW + 2 * self::LID_CLEARANCE_MM;
        $capaD = $bercoD + 2 * self::LID_CLEARANCE_MM;

        // Lombada = altura do berço + as duas capas que ela articula.
        $lombada = $bercoH + 2 * $t;

        $canaleta = self::HINGE_GAP_RATIO * $t;

        /*
         * Aba de fechamento: desce sobre a lateral aberta, então precisa vencer
         * a altura do berço e a espessura da própria contracapa. Zero na
         * variação sem aba — e o `null` de canaleta junto, senão a variação
         * simples pagaria uma dobradiça que não tem.
         */
        $aba = $model->hasClosingFlap() ? $bercoH + $t : 0.0;

        /*
         * Aba do fecho magnético — a assinatura da família ímã.
         *
         * Ela desce da tampa sobre a parede frontal, então precisa vencer a
         * altura do berço mais a espessura da própria tampa. A variação
         * envolvente vai além: dobra sob o fundo, e o quanto ela avança é o que
         * a separa das outras duas.
         */
        $magnetFlap = match (true) {
            $model === BoxModel::RigidMagnetWrap => $bercoH + $t + self::MAGNET_PAD_MM
                + self::MAGNET_WRAP_UNDER_RATIO * $capaD,
            $model->isMagnet() => $bercoH + $t + self::MAGNET_PAD_MM,
            default => 0.0,
        };

        /*
         * Abas laterais: descem pelas laterais e se recolhem para dentro.
         *
         * Correm ao longo da PROFUNDIDADE da capa (não da largura), e por isso
         * entram na área como painéis à parte em vez de esticar a capa corrida.
         */
        $sideFlap = $model === BoxModel::RigidMagnetSide ? $bercoH + $t : 0.0;
        $sideFlapCount = $model === BoxModel::RigidMagnetSide ? 2 : 0;

        /*
         * Dobradiças no REVESTIMENTO: contracapa↔lombada, lombada↔tampa, e uma
         * por aba articulada. Cada uma é uma canaleta que o papel atravessa —
         * e que o papelão não paga.
         */
        $dobradicas = 2
            + ($model->hasClosingFlap() ? 1 : 0)
            + ($magnetFlap > 0 ? 1 : 0)
            + $sideFlapCount;

        return [
            'bercoW' => $bercoW,
            'bercoD' => $bercoD,
            'bercoH' => $bercoH,
            'capaW' => $capaW,
            'capaD' => $capaD,
            'lombada' => $lombada,
            'aba' => $aba,
            'magnetFlap' => $magnetFlap,
            'sideFlap' => $sideFlap,
            'sideFlapCount' => (float) $sideFlapCount,
            'canaleta' => $canaleta,
            'dobradicas' => (float) $dobradicas,
        ];
    }

    /**
     * Caixa livro = capa de painéis + berço de quatro paredes, em papelão cinza.
     *
     * A CANALETA NÃO ENTRA AQUI, e é o ponto que separa este modelo dos
     * demais: os painéis da capa são cortados separados, e o vão entre eles é
     * ar — papelão que ninguém compra. Somá-lo cobraria do cliente o espaço
     * vazio da dobradiça. Ele reaparece em bookWrapArea(), onde é real: o papel
     * atravessa a fenda inteira.
     *
     * @return array{width: float, height: float}
     */
    private function bookBlank(BoxModel $model, float $w, float $h, float $d, float $t): array
    {
        $l = $this->bookLayout($model, $w, $h, $d, $t);

        // Capa: contracapa + lombada + tampa (+ abas), lado a lado, sem os vãos.
        $capaCorrida = 2 * $l['capaW'] + $l['lombada'] + $l['aba'] + $l['magnetFlap']
            + self::RIGID_TRIM_MM;
        $capaAltura = $l['capaD'] + self::RIGID_TRIM_MM;

        // Abas laterais correm ao longo da profundidade: painéis à parte, e não
        // um prolongamento da capa corrida.
        $areaLaterais = $l['sideFlapCount'] * ($l['sideFlap'] * $l['capaD']);

        // Berço: a mesma cruz da tampa solta — fundo com as quatro paredes.
        $bercoCruzW = $w + 2 * $h + 2 * $t + self::RIGID_TRIM_MM;
        $bercoCruzH = $d + 2 * $h + 2 * $t + self::RIGID_TRIM_MM;

        $totalArea = ($capaCorrida * $capaAltura) + $areaLaterais
            + ($bercoCruzW * $bercoCruzH);

        $width = max($capaCorrida, $bercoCruzW);

        return [
            'width' => $width,
            'height' => $totalArea / $width,
        ];
    }

    /**
     * Revestimento da caixa livro, em mm² — capa aberta + berço.
     *
     * AQUI a canaleta conta. O papel é uma folha só que cobre os três painéis E
     * os vãos entre eles: é ele que vira a dobradiça. Descontar a canaleta
     * daria uma folha curta demais para colar, e a caixa sairia da produção sem
     * a articulação que a define.
     */
    private function bookWrapArea(BoxModel $model, float $w, float $h, float $d, float $t): float
    {
        $l = $this->bookLayout($model, $w, $h, $d, $t);

        $capaAberta = 2 * $l['capaW'] + $l['lombada'] + $l['aba'] + $l['magnetFlap']
            + $l['dobradicas'] * $l['canaleta'];

        $capa = ($capaAberta + 2 * self::TURN_IN_MM) * ($l['capaD'] + 2 * self::TURN_IN_MM);

        // Cada aba lateral é revestida como painel próprio, com virada nas
        // quatro bordas: ela fica exposta pelos dois lados ao abrir a caixa.
        $laterais = $l['sideFlapCount']
            * (($l['sideFlap'] + 2 * self::TURN_IN_MM) * ($l['capaD'] + 2 * self::TURN_IN_MM));

        // O berço é revestido por dentro pela mesma cruz da tampa solta.
        $berco = $this->rigidWrapPanel($w, $h, $d, $t);

        return $capa + $laterais + $berco;
    }

    /**
     * Retângulo de revestimento de UMA peça rígida, em mm².
     *
     * A cruz do cinza mais a virada nas quatro bordas. A espessura entra duas
     * vezes por eixo pelo mesmo motivo do cinza (o papel sobe a lateral do
     * painel), e a virada entra outras duas — uma por borda.
     *
     * Não se desconta o vazio dos cantos: o revestimento é cortado em retângulo
     * inteiro e os cantos são recortados DEPOIS, já colado. A apara existe de
     * qualquer jeito.
     */
    private function rigidWrapPanel(float $w, float $h, float $d, float $t): float
    {
        $largura = $w + 2 * $h + 2 * $t + 2 * self::TURN_IN_MM;
        $altura = $d + 2 * $h + 2 * $t + 2 * self::TURN_IN_MM;

        return $largura * $altura;
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

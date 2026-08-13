import type {
  BoxModel,
  CradleType,
  CompanyHourBreakdown,
  CompanyHourParams,
  CostSettings,
  CustomPartLine,
  EfficiencyPercent,
  EfficiencyScenarioResult,
  Material,
  PricingBreakdown,
  PricingMode,
  QuoteSpecification,
} from "./types";

/**
 * ⚠️  ESPELHO de backend/app/Services/Pricing/PricingEngine.php
 *
 * Este módulo existe por uma única razão: latência. Aguardar um round-trip HTTP
 * a cada tecla digitada tornaria a calculadora lenta e o preview 3D dessincro-
 * nizado dos números. Então calculamos localmente para exibir na hora.
 *
 * REGRAS DE CONVIVÊNCIA COM O BACKEND:
 *  1. O backend é a AUTORIDADE. Ao salvar, ele recalcula e o resultado dele
 *     prevalece — o que este arquivo produz nunca é persistido.
 *  2. Toda alteração de fórmula aqui exige a alteração equivalente no PHP.
 *  3. ENGINE_VERSION deve acompanhar a constante VERSION do PHP; a tela alerta
 *     o usuário se a API responder com uma versão diferente (motor defasado
 *     após um deploy parcial).
 */
export const ENGINE_VERSION = "1.5.0";

const MM2_PER_M2 = 1_000_000;
const MM3_PER_M3 = 1_000_000_000;
const MINUTES_PER_HOUR = 60;

/* ────────────────────────────────────────────────────────────────────────────
 * Geometria — espelha BlankCalculator.php
 * ──────────────────────────────────────────────────────────────────────────── */

const GLUE_FLAP_MM = 35;
const LID_CLEARANCE_MM = 2;
const LID_HEIGHT_RATIO = 0.35;
const SEAL_MM = 10;

/**
 * Virada do revestimento sobre as bordas, em mm — cartonagem rígida.
 *
 * ⚠️ Espelha TURN_IN_MM de BlankCalculator.php.
 *
 * O papel não para na quina: dobra por cima dela e cola no lado de dentro.
 * Entra DUAS vezes por eixo (uma borda de cada lado), e é a razão de o
 * revestimento sempre consumir mais chapa que o cinza que ele cobre.
 */
const TURN_IN_MM = 15;

/** Sobra de esquadrejo do papelão cinza, em mm. ⚠️ Espelha RIGID_TRIM_MM. */
const RIGID_TRIM_MM = 8;

/**
 * Canaleta entre os painéis da capa, em múltiplos da espessura.
 *
 * ⚠️ Espelha HINGE_GAP_RATIO de BlankCalculator.php.
 *
 * É a fenda que permite a capa dobrar, e ela é VAZIA: quem une os painéis é o
 * papel de revestimento, que atravessa o vão e vira a dobradiça. Por isso a
 * canaleta não consome papelão, mas consome revestimento.
 */
const HINGE_GAP_RATIO = 1.5;

/**
 * Quanto a aba envolvente avança sob o fundo, em fração da profundidade.
 * ⚠️ Espelha MAGNET_WRAP_UNDER_RATIO de BlankCalculator.php.
 */
const MAGNET_WRAP_UNDER_RATIO = 0.25;

/**
 * Pad de assentamento do ímã na ponta da aba, em mm.
 * ⚠️ Espelha MAGNET_PAD_MM de BlankCalculator.php.
 *
 * A aba avança sobre a face frontal para que o ímã encontre o par do berço.
 * Em MILÍMETROS e não em espessuras: o ímã tem tamanho próprio, e papelão mais
 * fino não o faz encolher. É também o que a separa da aba do livro, que só
 * cobre a parede e se recolhe por atrito.
 */
const MAGNET_PAD_MM = 12;

/* ── Mailer: as razões da faca ───────────────────────────────────────────────
 *
 * Todas saem de `mailer/box-mailer.blend`, a peça que o cliente modelou — as
 * medidas foram tiradas do glTF exportado, painel a painel, e não do
 * `mailer.py` ao lado dele. A distinção custou caro para aparecer: o script no
 * disco é uma versão ANTERIOR do modelo, e nele a língua tem 70 onde a peça
 * real tem 84,5 e a barbatana 20×28 onde a real tem 31,8×38,8. Quem for
 * conferir, meça o .blend (ou o .glb), nunca o .py.
 *
 * Lá as medidas são fixas, porque o modelo é UMA caixa (300×250, parede 81,5,
 * papelão 3). Aqui viram razões, senão a caixa não redimensiona: numa
 * 100×300×100 as abas de 60mm se atravessam no meio do fundo. Cada razão
 * reproduz o painel medido naquela caixa, e a soma fecha a 1,3% da área do
 * modelo — diferença que é toda das duas convenções de recorte interno (as
 * fendas do fundo e a curva da barbatana, ver blankDimensions).
 */

/** Avanço das abas laterais das paredes, em fração da profundidade (60/250). */
const MAILER_TAB_RATIO = 0.24;

/**
 * Abas laterais da tampa, em fração da altura de parede (70/81,5).
 *
 * Descem por DENTRO, encostando na parede interna do rolo, e por isso param
 * antes do piso. A língua não usa esta razão: ela desce por fora e é medida à
 * parte, logo abaixo.
 */
const MAILER_LID_FLAP_RATIO = 0.86;

/**
 * Trecho da tampa que a aba lateral ocupa, em fração do comprimento dela.
 *
 * Medido no modelo: a aba vai de 5 a 209 numa tampa de 253. Ela começa depois
 * da parede traseira e PARA ANTES da barbatana — as duas descem no mesmo
 * plano, e sobrepô-las é o que impede a trava de fechar.
 */
const MAILER_LID_FLAP_START_RATIO = 0.02;
const MAILER_LID_FLAP_END_RATIO = 0.826;

/**
 * Lingueta que prende o rolo ao fundo, em fração da altura e da largura.
 *
 * Vale o MENOR dos dois: ela precisa ser curta o bastante para caber na fenda
 * (que está a uma lingueta da borda do fundo) e não pode ser mais comprida que
 * o alcance da parede interna. Na caixa do script as duas dão 18.
 */
const MAILER_LOCK_HEIGHT_RATIO = 0.22;
const MAILER_LOCK_WIDTH_RATIO = 0.06;

/** Fendas do fundo: centro e comprimento, em fração da profundidade. */
const MAILER_SLOT_CENTER_RATIO = 0.29;
const MAILER_SLOT_LENGTH_RATIO = 0.22;

/**
 * Avanço da barbatana, em fração do avanço da aba da parede (31,8/60).
 *
 * É a profundidade que ela entra no BOLSO — o vão entre a aba da parede
 * frontal e a parede interna do rolo. Quem limita é a aba: passar dela é sair
 * pelo outro lado do bolso, e a trava deixa de existir.
 */
const MAILER_FIN_OUT_RATIO = 0.53;

/** Faixa que a barbatana ocupa ao longo da língua (38,8/84,5). */
const MAILER_FIN_BAND_RATIO = 0.459;

/** Chanfro da ponta dianteira da aba da tampa, em fração da queda DELA (18 e 25 de 70). */
const MAILER_LID_FLAP_CHAMFER_X = 0.26;
const MAILER_LID_FLAP_CHAMFER_Y = 0.36;

/**
 * A faca da mailer, em mm: planos, painéis e abas.
 *
 * Não são "as abas" como nos outros modelos — é a faca inteira, painel a
 * painel, porque é assim que o custo desta caixa se soma.
 *
 * O DESENHO não lê daqui: ele carrega o modelo do Blender pronto (ver
 * MailerMesh). Os dois continuam de acordo por construção — cada razão abaixo
 * foi medida no mesmo modelo que a tela mostra — e não por compartilharem a
 * função. Mexeu no .blend, remeça: a paridade automática deste projeto compara
 * PHP com TS, nunca o preço com a figura.
 */
export interface MailerLayout {
  /** Espessura, repetida aqui para quem lê o layout não precisar do spec. */
  tMm: number;
  /** Largura, profundidade e altura de PAREDE — planos de dobra, não o vão livre. */
  widthMm: number;
  depthMm: number;
  wallMm: number;
  /** Ponte do rolo: o topo que engole duas espessuras ao dobrar 180°. */
  bridgeMm: number;
  /** Meia-largura dos planos, do mais largo (aba da parede) ao mais estreito. */
  xTabHingeMm: number;
  xInnerMm: number;
  xLidMm: number;
  xWallFrontMm: number;
  /** Meia-profundidade da parede interna do rolo. */
  yInnerMm: number;
  /** Comprimento da tampa, do vinco traseiro até a ponta da língua. */
  lidMm: number;
  /** Avanço das abas laterais das paredes frontal e traseira. */
  wallTabMm: number;
  /**
   * Queda da língua frontal, que desce por FORA: a parede inteira mais uma
   * espessura, para cobrir a borda do fundo. É mais funda que as abas da
   * tampa, que descem por dentro e param antes do piso.
   */
  frontFlapMm: number;
  lidFlapMm: number;
  /** Trecho da tampa que a aba lateral ocupa, e o chanfro da ponta da frente. */
  lidFlapStartMm: number;
  lidFlapEndMm: number;
  lidFlapChamferXMm: number;
  lidFlapChamferYMm: number;
  /** Lingueta do rolo e a fenda do fundo que a recebe. */
  lockMm: number;
  slotStartMm: number;
  slotEndMm: number;
  /** Barbatana: quanto entra no bolso e quanto ocupa da língua. */
  finOutMm: number;
  finBandMm: number;
}

/**
 * Layout da faca da mailer — espelha BlankCalculator::mailerLayout().
 *
 * As medidas digitadas são as INTERNAS, e o script trabalha em planos de dobra:
 * a conversão soma a folga que cada camada rouba do vão livre.
 *
 *   largura: 5t — as duas paredes internas do rolo, com duas espessuras e meia
 *            de cada lado (a ponte mais a própria camada)
 *   profundidade: t — meia espessura da parede frontal e meia da traseira
 *   altura: t/2 — meia espessura do piso, já que a tampa pousa por cima
 *
 * Somar em vez de subtrair não é detalhe de sinal: é o que mantém a mailer
 * coerente com o resto do motor, onde papelão mais grosso SEMPRE pede blank
 * maior (o RSC soma 4t, a bandeja 2t). Tratando o digitado como externo, a
 * caixa encolhia por dentro e o preço caía junto — papelão grosso saindo mais
 * barato que fino, o que nenhum convertedor aceitaria ver na tela.
 *
 * Todo teto aqui é geométrico, não estético: dois painéis que se atravessam.
 */
export function mailerLayout(
  widthMm: number,
  heightMm: number,
  depthMm: number,
  thicknessMm: number,
): MailerLayout {
  const t = thicknessMm;

  const w = widthMm + 5 * t;
  const d = depthMm + t;
  const hw = heightMm + t / 2;

  const bridge = 2 * t;

  // Os quatro planos verticais, cada um recuado do anterior por uma espessura:
  // aba da parede traseira, parede interna do rolo, tampa, parede frontal. É
  // esse escalonamento que faz cada peça caber DENTRO da anterior ao fechar.
  const xTabHinge = Math.max(w / 2 - t, 0);
  const xInner = Math.max(w / 2 - bridge, 0);
  const xLid = Math.max(w / 2 - bridge - t, 0);
  const xWallFront = Math.max(xLid - t, 0);

  const wallTab = Math.min(MAILER_TAB_RATIO * d, Math.max(d / 2 - t, 0));
  const lidFlap = Math.min(MAILER_LID_FLAP_RATIO * hw, Math.max(hw - t, 0));
  const finOut = Math.min(MAILER_FIN_OUT_RATIO * wallTab, Math.max(wallTab - t, 0));

  // A língua cobre a parede inteira MAIS uma espessura — ela desce por fora e
  // precisa passar da borda do fundo. O teto é a própria tampa: mais comprida
  // que isso e a língua encostaria no chão antes de fechar.
  const frontFlap = Math.min(hw + t, d);

  // A tampa vai do vinco traseiro até uma espessura além da parede frontal —
  // é o que põe a língua do lado de FORA dela.
  const lid = d + t;

  const slotHalf = (MAILER_SLOT_LENGTH_RATIO * d) / 2;
  const slotCenter = Math.min(
    MAILER_SLOT_CENTER_RATIO * d,
    Math.max(d / 2 - t - slotHalf, 0),
  );

  return {
    tMm: t,
    widthMm: w,
    depthMm: d,
    wallMm: hw,
    bridgeMm: bridge,
    xTabHingeMm: xTabHinge,
    xInnerMm: xInner,
    xLidMm: xLid,
    xWallFrontMm: xWallFront,
    yInnerMm: Math.max(d / 2 - t / 2, 0),
    lidMm: lid,
    wallTabMm: wallTab,
    frontFlapMm: frontFlap,
    lidFlapMm: lidFlap,
    lidFlapStartMm: MAILER_LID_FLAP_START_RATIO * lid,
    lidFlapEndMm: MAILER_LID_FLAP_END_RATIO * lid,
    lidFlapChamferXMm: MAILER_LID_FLAP_CHAMFER_X * lidFlap,
    lidFlapChamferYMm: MAILER_LID_FLAP_CHAMFER_Y * lidFlap,
    lockMm: Math.min(
      MAILER_LOCK_HEIGHT_RATIO * hw,
      MAILER_LOCK_WIDTH_RATIO * w,
      xInner,
    ),
    slotStartMm: Math.max(slotCenter - slotHalf, 0),
    slotEndMm: slotCenter + slotHalf,
    finOutMm: finOut,
    finBandMm: Math.min(MAILER_FIN_BAND_RATIO * frontFlap, Math.max(frontFlap - t, 0)),
  };
}

export interface BlankDimensions {
  width: number;
  height: number;
}

/** Modelos com seção circular: a largura passa a significar o diâmetro. */
export function isCylindrical(model: BoxModel): boolean {
  return model === "tube";
}

/** Modelos que têm uma tampa como peça separada. */
export function hasSeparateLid(model: BoxModel): boolean {
  return model === "tray" || model === "tube" || model === "rigid_telescopic";
}

/**
 * Família da capa rígida: painéis articulados + berço colado.
 *
 * Livro e ímã compartilham a construção inteira — a diferença está nas abas e
 * no fecho. ⚠️ Espelha isBook() do PHP.
 */
export function isBook(model: BoxModel): boolean {
  return (
    model === "rigid_book" ||
    model === "rigid_book_flap" ||
    isMagnet(model)
  );
}

/** Família ímã: a aba frontal aloja o fecho magnético. */
export function isMagnet(model: BoxModel): boolean {
  return (
    model === "rigid_magnet" ||
    model === "rigid_magnet_side" ||
    model === "rigid_magnet_wrap"
  );
}

/** A capa tem um quarto painel que fecha a lateral aberta (só o livro). */
export function hasClosingFlap(model: BoxModel): boolean {
  return model === "rigid_book_flap";
}

/**
 * Ímãs sugeridos para o formulário e para o desenho.
 *
 * SUGESTÃO e não regra: quem cobra é a lista de materiais. O número existe
 * para o usuário não começar do zero e esquecer de lançar a ferragem — o
 * esquecimento mais caro do modelo, porque o ímã não aparece na foto.
 *
 * ⚠️ Espelha suggestedMagnets() do PHP.
 */
export function suggestedMagnets(model: BoxModel): number {
  if (model === "rigid_magnet_side") return 4;
  if (model === "rigid_magnet" || model === "rigid_magnet_wrap") return 2;

  return 0;
}

export interface BookLayout {
  bercoW: number;
  bercoD: number;
  bercoH: number;
  capaW: number;
  capaD: number;
  lombada: number;
  aba: number;
  magnetFlap: number;
  sideFlap: number;
  sideFlapCount: number;
  canaleta: number;
  dobradicas: number;
}

/**
 * Geometria da caixa livro, em mm — a fonte única dos dois blanks e do 3D.
 *
 * ⚠️ Espelha bookLayout() de BlankCalculator.php. O renderizador lê daqui os
 * MESMOS números que entram no preço: um painel que exista na figura e não na
 * conta (ou o contrário) é uma divergência que nenhum teste deste projeto
 * enxerga, porque a paridade só compara PHP com TS.
 *
 * As medidas informadas são as INTERNAS do berço. A lombada precisa vencer a
 * altura do berço MAIS as duas capas que ela une — é o erro clássico do
 * modelo: lombada curta deixa a caixa arqueada e sem fechar.
 */
export function bookLayout(
  model: BoxModel,
  widthMm: number,
  heightMm: number,
  depthMm: number,
  t: number,
): BookLayout {
  const bercoW = widthMm + 2 * t;
  const bercoD = depthMm + 2 * t;
  const bercoH = heightMm + t;

  const capaW = bercoW + 2 * LID_CLEARANCE_MM;
  const capaD = bercoD + 2 * LID_CLEARANCE_MM;

  const lombada = bercoH + 2 * t;
  const canaleta = HINGE_GAP_RATIO * t;

  const aba = hasClosingFlap(model) ? bercoH + t : 0;

  /*
   * Aba do fecho magnético: desce da tampa sobre a parede frontal, vencendo a
   * altura do berço mais a espessura da tampa. A envolvente vai além e dobra
   * sob o fundo — é o quanto ela avança que a separa das outras duas.
   */
  const magnetFlap =
    model === "rigid_magnet_wrap"
      ? bercoH + t + MAGNET_PAD_MM + MAGNET_WRAP_UNDER_RATIO * capaD
      : isMagnet(model)
        ? bercoH + t + MAGNET_PAD_MM
        : 0;

  // Correm ao longo da PROFUNDIDADE da capa: painéis à parte, e não um
  // prolongamento da capa corrida.
  const sideFlap = model === "rigid_magnet_side" ? bercoH + t : 0;
  const sideFlapCount = model === "rigid_magnet_side" ? 2 : 0;

  // Dobradiças no REVESTIMENTO: as duas da capa mais uma por aba articulada.
  const dobradicas =
    2 + (hasClosingFlap(model) ? 1 : 0) + (magnetFlap > 0 ? 1 : 0) + sideFlapCount;

  return {
    bercoW,
    bercoD,
    bercoH,
    capaW,
    capaD,
    lombada,
    aba,
    magnetFlap,
    sideFlap,
    sideFlapCount,
    canaleta,
    dobradicas,
  };
}

/**
 * Cartonagem RÍGIDA: papelão cinza revestido com papel, em vez de uma chapa
 * vincada que se sustenta sozinha. ⚠️ Espelha BoxModel::isRigid() do PHP.
 */
export function isRigid(model: BoxModel): boolean {
  return model === "rigid_telescopic" || isBook(model);
}

/**
 * Não há planificação a calcular: as peças vêm da mão do usuário.
 * ⚠️ Espelha BoxModel::isFree() do PHP.
 */
export function isFree(model: BoxModel): boolean {
  return model === "free";
}

/**
 * Soma as peças medidas à mão do modelo livre.
 *
 * ⚠️ Espelha PricingEngine::customPartsConsumption() do PHP.
 *
 * Separa estrutura de revestimento porque são linhas diferentes da ficha de
 * custo, e cada peça aplica a PRÓPRIA perda — o usuário mistura papelão (12%),
 * kraft (8%) e tecido (15%) no mesmo orçamento, e um percentual único trataria
 * os três igual.
 */
export function customPartsConsumption(parts: CustomPartLine[]): {
  structureNetM2: number;
  structureGrossM2: number;
  structureCost: number;
  wrapNetM2: number;
  wrapGrossM2: number;
  wrapCost: number;
} {
  if (parts.length === 0) {
    /*
     * Sem peça não há caixa. O PHP lança DomainException (que vira 422); aqui a
     * mensagem é a mesma, para a tela dizer a mesma coisa que a API diria.
     */
    throw new Error(
      "O modelo livre precisa de ao menos uma peça. " +
        "Informe as chapas e folhas que serão cortadas, com medida e quantidade.",
    );
  }

  const totais = {
    structureNetM2: 0,
    structureGrossM2: 0,
    structureCost: 0,
    wrapNetM2: 0,
    wrapGrossM2: 0,
    wrapCost: 0,
  };

  for (const part of parts) {
    // Quantidade é POR CAIXA — o lote entra depois, com o resto do orçamento.
    const liquida = ((part.width_mm * part.length_mm) / MM2_PER_M2) * part.quantity;
    const bruta = liquida * (1 + part.waste_percent / 100);

    if (part.role === "wrap") {
      totais.wrapNetM2 += liquida;
      totais.wrapGrossM2 += bruta;
      totais.wrapCost += bruta * part.cost_per_m2;
    } else {
      totais.structureNetM2 += liquida;
      totais.structureGrossM2 += bruta;
      totais.structureCost += bruta * part.cost_per_m2;
    }
  }

  return totais;
}

/**
 * Área do REVESTIMENTO por peça, em m² — só na cartonagem rígida.
 *
 * A mesma planificação do cinza acrescida da virada em todas as bordas, mais a
 * espessura, porque o papel percorre a lateral do painel antes de dobrar.
 * Devolve 0 nos modelos dobrados, onde o material é um só.
 *
 * ⚠️ Espelha wrapAreaInSquareMeters() de BlankCalculator.php.
 */
export function wrapAreaInSquareMeters(
  model: BoxModel,
  widthMm: number,
  heightMm: number,
  depthMm: number,
  thicknessMm: number,
  lidMm?: LidDimensions | null,
): number {
  if (!isRigid(model)) return 0;

  const t = thicknessMm;

  const panel = (w: number, h: number, d: number): number =>
    (w + 2 * h + 2 * t + 2 * TURN_IN_MM) * (d + 2 * h + 2 * t + 2 * TURN_IN_MM);

  /*
   * Caixa livro: AQUI a canaleta conta. O papel é uma folha só que cobre os
   * três painéis E os vãos entre eles — é ele que vira a dobradiça. Descontar
   * a canaleta daria uma folha curta demais para colar.
   */
  if (isBook(model)) {
    const l = bookLayout(model, widthMm, heightMm, depthMm, t);

    const capaAberta =
      2 * l.capaW + l.lombada + l.aba + l.magnetFlap + l.dobradicas * l.canaleta;

    const capa = (capaAberta + 2 * TURN_IN_MM) * (l.capaD + 2 * TURN_IN_MM);

    // Cada aba lateral é revestida como painel próprio: fica exposta pelos
    // dois lados ao abrir a caixa.
    const laterais =
      l.sideFlapCount *
      ((l.sideFlap + 2 * TURN_IN_MM) * (l.capaD + 2 * TURN_IN_MM));

    return (capa + laterais + panel(widthMm, heightMm, depthMm)) / MM2_PER_M2;
  }

  const lid =
    lidMm ?? defaultLidDimensions(model, widthMm, heightMm, depthMm, t)!;

  const area =
    panel(widthMm, heightMm, depthMm) +
    panel(lid.widthMm, lid.heightMm, lid.depthMm);

  return area / MM2_PER_M2;
}

/** Medidas físicas da tampa, em milímetros. */
export interface LidDimensions {
  widthMm: number;
  depthMm: number;
  heightMm: number;
}

/** Medidas da tampa informadas pelo usuário; null em um eixo = automático. */
export interface LidOverrides {
  lid_width_mm?: number | null;
  lid_depth_mm?: number | null;
  lid_height_mm?: number | null;
}

/**
 * Dimensões FÍSICAS SUGERIDAS da tampa (não do plano de corte).
 *
 * Espelha BlankCalculator::defaultLidDimensions(). Responde "que tamanho tem
 * a peça", enquanto blankDimensions responde "quanto material comprar".
 *
 * É a fonte única consumida pelo painel de medidas, pelos campos do
 * formulário e pelo renderizador 3D — sem ela, as constantes de folga e de
 * altura da tampa acabariam duplicadas em três lugares.
 *
 * Devolve null para modelos sem tampa separada.
 */
export function defaultLidDimensions(
  model: BoxModel,
  widthMm: number,
  heightMm: number,
  depthMm: number,
  thicknessMm = 0,
): LidDimensions | null {
  if (!hasSeparateLid(model)) return null;

  // A tampa encaixa POR FORA da base: vence a folga de encaixe e a espessura
  // das paredes dela própria.
  const folga = 2 * LID_CLEARANCE_MM + 2 * thicknessMm;

  // Num cilindro largura e profundidade são o mesmo diâmetro; devolver
  // valores diferentes produziria uma tampa oval.
  const baseDepth = isCylindrical(model) ? widthMm : depthMm;

  return {
    widthMm: widthMm + folga,
    depthMm: baseDepth + folga,
    heightMm: heightMm * LID_HEIGHT_RATIO,
  };
}

/**
 * Medidas efetivas: o que o usuário informou, ou a sugestão.
 *
 * Cada eixo é independente — dá para fixar só a altura da tampa e deixar
 * largura e profundidade acompanhando a base.
 */
export function resolveLidDimensions(
  model: BoxModel,
  widthMm: number,
  heightMm: number,
  depthMm: number,
  thicknessMm = 0,
  overrides: LidOverrides = {},
): LidDimensions | null {
  const padrao = defaultLidDimensions(model, widthMm, heightMm, depthMm, thicknessMm);

  if (!padrao) return null;

  return {
    widthMm: overrides.lid_width_mm ?? padrao.widthMm,
    depthMm: overrides.lid_depth_mm ?? padrao.depthMm,
    heightMm: overrides.lid_height_mm ?? padrao.heightMm,
  };
}

/**
 * Dimensões do plano de corte (blank) em milímetros.
 *
 * Não é a soma das 6 faces: uma embalagem é uma chapa única dobrada, com abas
 * de colagem e de fechamento que se sobrepõem. Somar faces subestima o consumo
 * de material — num RSC, em 15–30%.
 */
export function blankDimensions(
  model: BoxModel,
  widthMm: number,
  heightMm: number,
  depthMm: number,
  thicknessMm = 0,
  /** Medidas efetivas da tampa; null usa a sugestão. */
  lidMm: LidDimensions | null = null,
): BlankDimensions {
  const t = thicknessMm; // compensação de dobra: cada vinco consome ~1 espessura

  switch (model) {
    case "rsc":
      return {
        width: 2 * (widthMm + depthMm) + GLUE_FLAP_MM + 4 * t,
        height: heightMm + depthMm + 2 * t,
      };

    case "tray": {
      // Base (fundo + 4 paredes rebatidas)
      const baseW = widthMm + 2 * heightMm + 2 * t;
      const baseH = depthMm + 2 * heightMm + 2 * t;

      // Tampa: planificação das medidas físicas EFETIVAS. Usar a sugestão
      // aqui faria uma tampa customizada mais alta sair de graça.
      const lid =
        lidMm ?? defaultLidDimensions(model, widthMm, heightMm, depthMm, t)!;

      const lidW = lid.widthMm + 2 * lid.heightMm;
      const lidH = lid.depthMm + 2 * lid.heightMm;

      // Duas peças reduzidas a um retângulo de área equivalente.
      const totalArea = baseW * baseH + lidW * lidH;
      const width = Math.max(baseW, lidW);

      return { width, height: totalArea / width };
    }

    /*
     * Cartonagem rígida: este blank é o do PAPELÃO CINZA. O revestimento tem
     * área própria — ver wrapAreaInSquareMeters().
     *
     * Cada peça é uma cruz: o fundo com as quatro paredes ao redor. Diferente
     * da bandeja dobrada, a cruz aqui não é vincada — é o desenho de corte de
     * cinco painéis que serão colados em esquadro. Para o consumo de chapa dá
     * no mesmo, e os quatro cantos vazios são apara já coberta pelo desperdício.
     *
     * ⚠️ Espelha rigidTelescopicBlank() de BlankCalculator.php.
     */
    case "rigid_telescopic": {
      const lid =
        lidMm ?? defaultLidDimensions(model, widthMm, heightMm, depthMm, t)!;

      const baseW = widthMm + 2 * heightMm + 2 * t + RIGID_TRIM_MM;
      const baseH = depthMm + 2 * heightMm + 2 * t + RIGID_TRIM_MM;

      const lidW = lid.widthMm + 2 * lid.heightMm + 2 * t + RIGID_TRIM_MM;
      const lidH = lid.depthMm + 2 * lid.heightMm + 2 * t + RIGID_TRIM_MM;

      const totalArea = baseW * baseH + lidW * lidH;
      const width = Math.max(baseW, lidW);

      return { width, height: totalArea / width };
    }

    /*
     * Caixa livro: capa de painéis + berço de quatro paredes.
     *
     * A CANALETA NÃO ENTRA AQUI, e é o que separa este modelo dos demais: os
     * painéis da capa são cortados separados, e o vão entre eles é ar —
     * papelão que ninguém compra. Somá-lo cobraria do cliente o espaço vazio
     * da dobradiça. Ele reaparece em wrapAreaInSquareMeters(), onde é real.
     *
     * ⚠️ Espelha bookBlank() de BlankCalculator.php.
     */
    case "rigid_book":
    case "rigid_book_flap":
    case "rigid_magnet":
    case "rigid_magnet_side":
    case "rigid_magnet_wrap": {
      const l = bookLayout(model, widthMm, heightMm, depthMm, t);

      const capaCorrida =
        2 * l.capaW + l.lombada + l.aba + l.magnetFlap + RIGID_TRIM_MM;
      const capaAltura = l.capaD + RIGID_TRIM_MM;

      const areaLaterais = l.sideFlapCount * (l.sideFlap * l.capaD);

      const bercoCruzW = widthMm + 2 * heightMm + 2 * t + RIGID_TRIM_MM;
      const bercoCruzH = depthMm + 2 * heightMm + 2 * t + RIGID_TRIM_MM;

      const totalArea =
        capaCorrida * capaAltura + areaLaterais + bercoCruzW * bercoCruzH;

      const width = Math.max(capaCorrida, bercoCruzW);

      return { width, height: totalArea / width };
    }

    case "sleeve":
      return {
        width: 2 * (widthMm + depthMm) + GLUE_FLAP_MM + 4 * t,
        height: heightMm,
      };

    case "pouch":
      return {
        width: widthMm + 2 * SEAL_MM,
        height: 2 * heightMm + depthMm + 2 * SEAL_MM,
      };

    case "drawer": {
      // Caixa gaveta: gaveta interna (bandeja) + luva externa.
      //
      // A luva envolve a gaveta JÁ MONTADA — vence as paredes dela (duas
      // espessuras por eixo) mais a folga de deslize. Dimensioná-la pela
      // caixa "por fora" produziria uma gaveta que não entra.
      const gavetaW = widthMm + 2 * heightMm + 2 * t;
      const gavetaH = depthMm + 2 * heightMm + 2 * t;

      const secaoLargura = widthMm + 2 * t + LID_CLEARANCE_MM;
      const secaoAltura = heightMm + 2 * t + LID_CLEARANCE_MM;

      const luvaW = 2 * (secaoLargura + secaoAltura) + GLUE_FLAP_MM;
      const luvaH = depthMm;

      const totalArea = gavetaW * gavetaH + luvaW * luvaH;
      const width = Math.max(gavetaW, luvaW);

      return { width, height: totalArea / width };
    }

    case "mailer": {
      /*
       * Mailer box (RETT): uma chapa só, die-cut, dobrada sem cola.
       *
       * A soma abaixo percorre painel a painel, na ordem em que `mailer.py`
       * os cria — e é a MESMA decomposição que o MailerMesh desenha. É o
       * critério escolhido para este modelo: o que aparece na tela é o que
       * entra na conta. Um painel que existe no preço e não no desenho (ou o
       * contrário) é uma divergência que nenhum teste deste projeto enxerga,
       * porque a paridade automática compara PHP com TS, nunca preço com
       * figura.
       *
       * Recortes internos — fendas, entalhe de dedo, chanfros — não são
       * descontados: são aparas que o percentual de desperdício já cobre.
       */

      const L = mailerLayout(widthMm, heightMm, depthMm, t);

      const area =
        // Fundo. As quatro fendas que recebem as linguetas são recorte
        // INTERNO: a chapa precisou existir inteira, e a apara delas fica no
        // percentual de desperdício, como o entalhe e os chanfros.
        L.widthMm * L.depthMm +
        // Paredes frontal e traseira. A frontal é mais estreita de propósito:
        // ela recua para dar passagem à barbatana, que desce no plano da tampa.
        2 * L.xWallFrontMm * L.wallMm +
        2 * L.xTabHingeMm * L.wallMm +
        // Abas laterais das duas paredes, alojadas no bolso do rolo.
        4 * (L.wallTabMm * L.wallMm) +
        // Painel da tampa, do vinco traseiro até uma espessura além da frente.
        2 * L.xLidMm * L.lidMm +
        // Abas laterais da tampa, com a ponta dianteira chanfrada para liberar
        // o bolso onde a barbatana entra.
        2 *
          ((L.lidFlapEndMm - L.lidFlapStartMm) * L.lidFlapMm -
            (L.lidFlapChamferXMm * L.lidFlapChamferYMm) / 2) +
        // Língua frontal, que desce por fora da parede.
        2 * L.xLidMm * L.frontFlapMm +
        /*
         * As duas barbatanas, contadas pelo retângulo que as envolve.
         *
         * O contorno real é bézier, e a área exata exigiria o mesmo shoelace
         * em PHP e em TS para a paridade fechar. Não vale: a apara da curva
         * está DENTRO do retângulo que a faca precisa liberar de qualquer
         * jeito, e é o mesmo critério já usado para o entalhe e os chanfros.
         */
        2 * (L.finOutMm * L.finBandMm) +
        /*
         * O rolo, assinatura do modelo e o motivo de a mailer ser rígida: a
         * lateral sobe (externa), dobra 180° no topo (a ponte engole duas
         * espessuras) e desce por dentro até o piso (interna). Três painéis de
         * verdade — por isso cada milímetro de altura pesa aqui muito mais que
         * num RSC, onde a lateral é uma chapa só.
         */
        2 * (L.depthMm * L.wallMm) +
        2 * (L.depthMm * L.bridgeMm) +
        2 * (2 * L.yInnerMm * L.wallMm) +
        // As quatro linguetas que prendem o rolo ao fundo.
        4 * ((L.slotEndMm - L.slotStartMm) * L.lockMm);

      /*
       * Largura real da chapa, direto do `faca_mm` do script: metade da caixa
       * mais a coluna inteira do rolo (externa + ponte + interna + lingueta),
       * dos dois lados.
       */
      const width =
        L.widthMm + 2 * (L.wallMm + L.bridgeMm + L.wallMm + L.lockMm);

      return { width, height: area / width };
    }

    case "tube": {
      // A largura é o DIÂMETRO; a profundidade é ignorada.
      //
      // O corpo é planificado pela circunferência da LINHA MÉDIA da parede:
      // enrolar uma chapa faz a face externa percorrer caminho maior que a
      // interna, e usar o diâmetro interno produziria um tubo que não fecha.
      const bodyW = Math.PI * (widthMm + t) + GLUE_FLAP_MM;

      // Os discos são orçados pelo QUADRADO que os circunscreve: recortar um
      // disco descarta os cantos, e esse descarte é consumo real de material.
      let totalArea = bodyW * heightMm + widthMm ** 2;
      let widest = Math.max(bodyW, widthMm);

      const lid =
        lidMm ?? defaultLidDimensions(model, widthMm, heightMm, depthMm, t);

      if (lid) {
        const lidSkirtW = Math.PI * (lid.widthMm + t) + GLUE_FLAP_MM;

        totalArea += lidSkirtW * lid.heightMm + lid.widthMm ** 2;
        widest = Math.max(widest, lidSkirtW, lid.widthMm);
      }

      return { width: widest, height: totalArea / widest };
    }

    case "free":
      /*
       * O modelo livre não tem planificação — chegar aqui é erro de quem
       * chamou, não entrada inválida do usuário.
       *
       * calculatePricing() desvia antes, em isFree(). Devolver {0,0} em
       * silêncio seria pior que estourar: produziria uma área zero que se
       * propaga até um preço só de mão de obra, plausível e errado.
       */
      throw new Error(
        "blankDimensions não se aplica ao modelo livre — as peças vêm de custom_parts.",
      );
  }
}

/* ────────────────────────────────────────────────────────────────────────────
 * Berços de acomodação — espelha CradleCalculator.php
 * ──────────────────────────────────────────────────────────────────────────── */

/** ⚠️ Espelham as constantes de CradleCalculator.php. */
const CRADLE_CLEARANCE_MM = 1;
const DEFAULT_STRIP_THICKNESS_MM = 1.9;
const STRIP_TRIM_MM = 4;

/** Minutos que cada tipo de berço acrescenta. ⚠️ Espelha extraProductionMinutes(). */
export function cradleExtraMinutes(type: CradleType): number {
  switch (type) {
    case "foam":
      return 1.5;
    case "board_niche":
      return 8;
    case "paper_niche":
      return 4.5;
    case "paper_fold":
      return 2;
    case "divider_grid":
      return 3.5;
  }
}

/** Cobrado por volume (m³) em vez de área (m²)? */
export function isCradleVolumetric(type: CradleType): boolean {
  return type === "foam";
}

/**
 * O que um berço consome, na grandeza que o tipo dele cobra.
 *
 * ⚠️ Espelha CradleCalculator::consumption() passo a passo. As dimensões que
 * entram são as INTERNAS da caixa: o berço veste o vão útil, com folga para
 * entrar.
 */
export function cradleConsumption(
  type: CradleType,
  widthMm: number,
  heightMm: number,
  depthMm: number,
  rows = 1,
  columns = 1,
  heightRatio = 1,
  stripThicknessMm = 0,
): { area_m2: number; volume_m3: number; strips: number } {
  const w = Math.max(widthMm - 2 * CRADLE_CLEARANCE_MM, 0);
  const d = Math.max(depthMm - 2 * CRADLE_CLEARANCE_MM, 0);
  const h = Math.max(heightMm * heightRatio, 0);

  switch (type) {
    /*
     * Espuma: o bloco inteiro. O nicho recortado NÃO é descontado — o vazio
     * sai de um bloco já comprado, e o miolo é sobra. Descontá-lo faria o
     * berço mais elaborado sair mais barato que o simples.
     */
    case "foam":
      return { area_m2: 0, volume_m3: (w * d * h) / MM3_PER_M3, strips: 0 };

    // Nichos de cartonagem: fundo + quatro paredes, uma bandeja rasa.
    case "board_niche":
      return {
        area_m2: ((w + 2 * h) * (d + 2 * h)) / MM2_PER_M2,
        volume_m3: 0,
        strips: 0,
      };

    // Base de cartonagem MAIS a colmeia de papel: mais área que o nicho
    // rígido apesar de mais barato — o papel é fino e a colmeia tem muitas
    // paredes.
    case "paper_niche":
      return {
        area_m2: (w * d + 2 * (w + d) * h) / MM2_PER_M2,
        volume_m3: 0,
        strips: 0,
      };

    // Peça única dobrada: a cruz clássica.
    case "paper_fold":
      return {
        area_m2: ((w + 2 * h) * (d + 2 * h)) / MM2_PER_M2,
        volume_m3: 0,
        strips: 0,
      };

    case "divider_grid": {
      const transversais = Math.max(rows - 1, 0);
      const longitudinais = Math.max(columns - 1, 0);

      if (transversais + longitudinais === 0) {
        return { area_m2: 0, volume_m3: 0, strips: 0 };
      }

      const espessura =
        stripThicknessMm > 0 ? stripThicknessMm : DEFAULT_STRIP_THICKNESS_MM;

      /*
       * Cada tira perde a espessura das que a cruzam — sem isso a grade não
       * fecha no vão e as tiras estufam as paredes. A ranhura fêmea-fêmea não
       * desconta área: é um rasgo, e o que sai dele é apara.
       */
      const compTransversal =
        Math.max(w - longitudinais * espessura, 0) + STRIP_TRIM_MM;
      const compLongitudinal =
        Math.max(d - transversais * espessura, 0) + STRIP_TRIM_MM;

      const area =
        transversais * (compTransversal * h) +
        longitudinais * (compLongitudinal * h);

      return {
        area_m2: area / MM2_PER_M2,
        volume_m3: 0,
        strips: transversais + longitudinais,
      };
    }
  }
}

/* ────────────────────────────────────────────────────────────────────────────
 * Hora-empresa — espelha CompanyHourCalculator::compute() do PHP
 * ──────────────────────────────────────────────────────────────────────────── */

/**
 * Os três cenários, na ordem em que a interface exibe.
 *
 * ⚠️ Espelha EfficiencyScenario::comparison(), label() e description().
 * Do otimista ao conservador: a leitura de cima para baixo mostra o custo
 * SUBINDO conforme a estimativa fica realista, que é a lição do painel.
 */
export const EFFICIENCY_SCENARIOS: {
  percent: EfficiencyPercent;
  label: string;
  description: string;
}[] = [
  {
    percent: 100,
    label: "Sem eficiência (otimista)",
    description:
      "Considera que toda hora paga é hora produzida. Subestima o custo.",
  },
  {
    percent: 85,
    label: "Recomendado (equilibrado)",
    description: "Reserva 15% para setup, ajustes e imprevistos do dia a dia.",
  },
  {
    percent: 75,
    label: "Conservador (muitos imprevistos)",
    description:
      "Para operações com muita troca de pedido, retrabalho ou atendimento.",
  },
];

export interface CompanyHourArgs {
  /** Só as despesas ATIVAS; a tela filtra antes de chamar. */
  fixedCostAmounts: number[];
  equipment: { purchase_value: number; useful_life_months: number }[];
  params: CompanyHourParams;
}

/**
 * Custo da hora e do minuto da empresa, calculado no navegador.
 *
 * ⚠️ Espelha CompanyHourCalculator::compute() passo a passo, incluindo a ORDEM
 * dos arredondamentos. Divergir aqui faz a tela de configuração mostrar uma
 * hora e o motor de preço cobrar outra — e o usuário descobre isso pelo
 * orçamento errado, não por um erro na tela.
 *
 * Existe para que mexer na jornada, no fator ou no botão de depreciação
 * recalcule na hora, sem uma ida ao servidor por caractere digitado.
 */
export function calculateCompanyHour({
  fixedCostAmounts,
  equipment,
  params,
}: CompanyHourArgs): CompanyHourBreakdown {
  const fixedCostsTotal = round(
    fixedCostAmounts.reduce((total, value) => total + value, 0),
    2,
  );

  /*
   * Cada máquina é arredondada ANTES de entrar no total — o mesmo que o
   * append `monthly_depreciation` faz para a tela. Somar os valores cheios
   * daria um total que não fecha com a soma das linhas que o usuário vê.
   */
  const depreciationTotal = round(
    equipment.reduce(
      (total, m) =>
        total +
        (m.useful_life_months > 0
          ? round(m.purchase_value / m.useful_life_months, 2)
          : 0),
      0,
    ),
    2,
  );

  const costBase = round(
    fixedCostsTotal + (params.include_depreciation ? depreciationTotal : 0),
    2,
  );

  const monthlyHours = params.hours_per_day * params.days_per_month;

  if (monthlyHours <= 0) {
    throw new Error(
      "Informe as horas por dia e os dias por mês: sem jornada não há hora produtiva para ratear os custos.",
    );
  }

  const scenario = (
    cenario: (typeof EFFICIENCY_SCENARIOS)[number],
  ): EfficiencyScenarioResult => {
    const productiveHours = monthlyHours * (cenario.percent / 100);

    // 2 casas na hora: é o número que a tela exibe e o usuário confere.
    const hourCost = round(costBase / productiveHours, 2);

    /*
     * O minuto deriva da hora JÁ ARREDONDADA. Quem multiplicar o custo do
     * minuto por 60 na calculadora precisa chegar exatamente no custo da hora
     * que está na tela — um centavo de diferença entre dois números exibidos
     * lado a lado destrói a confiança na conta inteira.
     */
    const minuteCost = round(hourCost / 60, 4);

    return {
      efficiency_percent: cenario.percent,
      label: cenario.label,
      description: cenario.description,
      productive_hours: round(productiveHours, 2),
      hour_cost: hourCost,
      minute_cost: minuteCost,
    };
  };

  const ativo =
    EFFICIENCY_SCENARIOS.find((c) => c.percent === params.efficiency_percent) ??
    EFFICIENCY_SCENARIOS[1];

  return {
    parameters: params,
    cost_base: {
      fixed_costs: fixedCostsTotal,
      depreciation: params.include_depreciation ? depreciationTotal : 0,
      total: costBase,
    },
    monthly_hours: round(monthlyHours, 2),

    /*
     * Usa o total CHEIO do parque, e não a parcela que entrou na base: a
     * pergunta "quanto de máquina tem nesta caixa" não muda porque o usuário
     * desligou o botão — desligar muda como ele COBRA, não o que consome.
     */
    depreciation_per_unit:
      params.monthly_production_volume > 0
        ? round(depreciationTotal / params.monthly_production_volume, 4)
        : 0,

    active_scenario: scenario(ativo),
    comparison: EFFICIENCY_SCENARIOS.map(scenario),
  };
}

/* ────────────────────────────────────────────────────────────────────────────
 * Precificação — espelha PricingEngine.php
 * ──────────────────────────────────────────────────────────────────────────── */

export interface CalculateArgs {
  spec: QuoteSpecification;
  material: Material;
  settings: CostSettings;
}

/**
 * Cadeia de cálculo:
 *   área líquida ─(+desperdício)→ área bruta ─(×R$/m²)→ custo material
 *   tempo ─→ mão de obra + hora-máquina + energia
 *   custo direto ─(+rateio)→ CMV ─(+lucro)→ preço ─(+impostos)→ preço final
 */
export function calculatePricing({
  spec,
  material,
  settings,
}: CalculateArgs): PricingBreakdown {
  // ── 1. Geometria ──────────────────────────────────────────────────────────
  let lid: LidDimensions | null;
  let blank: { width: number; height: number };
  let netAreaPerUnit: number;
  let grossAreaPerUnit: number;
  let materialCost: number;
  let netWrapAreaPerUnit: number;
  let grossWrapAreaPerUnit: number;
  let wrapCost: number;

  if (isFree(spec.box_model)) {
    /*
     * Modelo livre: não há equação a aplicar.
     *
     * Todos os outros modelos derivam a planificação de largura, altura e
     * profundidade. Aqui a construção é desconhecida — é a caixa que o cliente
     * desenhou — e quem mede é o usuário. O motor apenas soma o que ele mediu.
     */
    const consumo = customPartsConsumption(spec.custom_parts ?? []);

    lid = null;

    // Não existe UM blank: existem N retângulos. Zero é a resposta honesta, e é
    // o que a ficha técnica lê para listar as peças em vez de desenhar.
    blank = { width: 0, height: 0 };

    netAreaPerUnit = consumo.structureNetM2;
    grossAreaPerUnit = consumo.structureGrossM2;
    materialCost = consumo.structureCost;

    netWrapAreaPerUnit = consumo.wrapNetM2;
    grossWrapAreaPerUnit = consumo.wrapGrossM2;
    wrapCost = consumo.wrapCost;
  } else {
    // A tampa é resolvida ANTES do plano de corte: o consumo de material precisa
    // refletir a tampa REAL, não a sugerida.
    lid = resolveLidDimensions(
      spec.box_model,
      spec.width_mm,
      spec.height_mm,
      spec.depth_mm,
      material.thickness_mm ?? 0,
      spec,
    );

    blank = blankDimensions(
      spec.box_model,
      spec.width_mm,
      spec.height_mm,
      spec.depth_mm,
      material.thickness_mm ?? 0,
      lid,
    );

    netAreaPerUnit = (blank.width * blank.height) / MM2_PER_M2;

    // Desperdício incide sobre a ÁREA (aparas, refile, setup), não sobre o custo.
    grossAreaPerUnit = netAreaPerUnit * (1 + spec.waste_percent / 100);

    /*
     * ── 2. Matéria-prima ──────────────────────────────────────────────────
     *
     * Sem custo por m² não há cálculo de área: é ímã (contado) ou espuma
     * (comprada em bloco). ⚠️ Espelha a DomainException de
     * Material::costPerSquareMeter(); tratar o null como zero produziria uma
     * caixa de graça — plausível na tela e errada na conta.
     */
    if (material.cost_per_m2 === null) {
      throw new Error(
        `"${material.name}" é cotado por peça ou por bloco e não tem custo por m². ` +
          "Escolha um material medido em área, como papelão ou papel.",
      );
    }

    materialCost = grossAreaPerUnit * material.cost_per_m2;

    /*
     * Revestimento: a segunda área da cartonagem rígida.
     *
     * O papel cobre o cinza e vira sobre as bordas, então consome MAIS chapa que
     * a estrutura — e custa mais por m². Tratar os dois como um material só
     * subestimaria justamente o mais caro. O desperdício incide igual: a mesma
     * guilhotina, as mesmas aparas.
     */
    netWrapAreaPerUnit = wrapAreaInSquareMeters(
      spec.box_model,
      spec.width_mm,
      spec.height_mm,
      spec.depth_mm,
      material.thickness_mm ?? 0,
      lid,
    );

    grossWrapAreaPerUnit = netWrapAreaPerUnit * (1 + spec.waste_percent / 100);

    wrapCost =
      spec.wrap_cost_per_m2 == null ? 0 : grossWrapAreaPerUnit * spec.wrap_cost_per_m2;
  }

  const grossAreaTotal = grossAreaPerUnit * spec.quantity;

  /*
   * Ferragem: contada, não medida. Sem desperdício percentual em cima — ímã
   * não tem apara, e quem quebra um lança a perda na quantidade, onde ela é
   * visível e discutível.
   */
  const hardwareCost = (spec.hardware ?? []).reduce(
    (total, item) => total + item.cost_per_piece * item.quantity,
    0,
  );

  /*
   * Berço de acomodação. A grandeza depende do TIPO: espuma é volume, o resto
   * é área. O desperdício incide nos dois — a espuma também tem apara de
   * corte, e ignorá-la faria o berço mais caro parecer o único sem perda.
   */
  let cradleCost = 0;
  let cradleMinutes = 0;
  let cradleAreaPerUnit = 0;
  let cradleVolumePerUnit = 0;

  if (spec.cradle) {
    const c = cradleConsumption(
      spec.cradle.type,
      spec.width_mm,
      spec.height_mm,
      spec.depth_mm,
      spec.cradle.rows,
      spec.cradle.columns,
      spec.cradle.height_ratio,
      spec.cradle.strip_thickness_mm,
    );

    cradleAreaPerUnit = c.area_m2;
    cradleVolumePerUnit = c.volume_m3;

    const grandeza = isCradleVolumetric(spec.cradle.type)
      ? cradleVolumePerUnit
      : cradleAreaPerUnit;

    cradleCost =
      grandeza * (1 + spec.waste_percent / 100) * spec.cradle.cost_per_unit;

    cradleMinutes = cradleExtraMinutes(spec.cradle.type);
  }

  // ── 3. Mão de obra e operacional ──────────────────────────────────────────
  // O tempo do berço entra na jornada da peça: é trabalho real, e deixá-lo de
  // fora faria a mão de obra da caixa com nichos custar o mesmo que a de uma
  // caixa vazia.
  const hours =
    (spec.production_minutes_per_unit + cradleMinutes) / MINUTES_PER_HOUR;

  /*
   * Dois regimes de custo indireto, e nunca os dois ao mesmo tempo.
   *
   * ESTIMATIVA (modo desligado, comportamento histórico): mão de obra por R$/h
   * digitado, indiretos por percentual sobre o custo direto. Dois palpites.
   *
   * HORA-EMPRESA (modo ligado): o minuto já carrega a despesa fixa real da
   * empresa, rateada pelas horas que de fato produzem.
   *
   * O rateio percentual ZERA no segundo regime porque cobra exatamente as
   * mesmas despesas — mantê-lo somaria aluguel sobre aluguel, com o erro
   * crescendo junto com o tempo de produção da peça.
   *
   * Espelha PricingEngine.php passo a passo; divergir aqui quebra a paridade.
   */
  const companyMinuteCost = settings.use_company_hour
    ? (settings.company_minute_cost ?? null)
    : null;

  const laborCost =
    companyMinuteCost !== null
      ? (spec.production_minutes_per_unit + cradleMinutes) * companyMinuteCost
      : hours * settings.labor_hour_rate;

  // Com o modo ligado e depreciação inclusa, este campo passa a valer
  // MANUTENÇÃO apenas — a depreciação já entrou pela hora-empresa.
  const machineCost = hours * settings.machine_hour_rate;
  const energyCost = hours * settings.machine_power_kw * settings.energy_tariff_per_kwh;

  // ── 4. CMV ────────────────────────────────────────────────────────────────
  const directCost =
    materialCost +
    wrapCost +
    hardwareCost +
    cradleCost +
    laborCost +
    machineCost +
    energyCost;

  const overheadCost =
    companyMinuteCost !== null ? 0 : directCost * (settings.overhead_percent / 100);

  const rawUnitCost = directCost + overheadCost;

  // ── 5. Preço ──────────────────────────────────────────────────────────────
  const rawUnitPrice = applyProfitAndTax(
    rawUnitCost,
    spec.profit_margin_percent,
    spec.pricing_mode,
    settings.tax_percent,
  );

  // ── 6. Totais ─────────────────────────────────────────────────────────────
  const unit_cost = money(rawUnitCost);
  const unit_price = money(rawUnitPrice);

  const total_cost = money(unit_cost * spec.quantity, 2);
  const total_price = money(unit_price * spec.quantity, 2);
  const tax_amount = money(total_price * (settings.tax_percent / 100), 2);
  const profit_amount = money(total_price - total_cost - tax_amount, 2);

  return {
    area_m2_per_unit: round(netAreaPerUnit, 6),
    area_m2_total: round(grossAreaTotal, 6),
    blank_width_mm: round(blank.width, 2),
    blank_height_mm: round(blank.height, 2),
    wrap_area_m2_per_unit: round(netWrapAreaPerUnit, 6),
    cradle_area_m2_per_unit: round(cradleAreaPerUnit, 6),
    cradle_volume_m3_per_unit: round(cradleVolumePerUnit, 9),

    lid_width_mm: lid ? round(lid.widthMm, 2) : null,
    lid_depth_mm: lid ? round(lid.depthMm, 2) : null,
    lid_height_mm: lid ? round(lid.heightMm, 2) : null,

    material_cost: money(materialCost),
    wrap_cost: money(wrapCost),
    hardware_cost: money(hardwareCost),
    cradle_cost: money(cradleCost),
    cradle_minutes: round(cradleMinutes, 2),
    labor_cost: money(laborCost),
    machine_cost: money(machineCost),
    energy_cost: money(energyCost),
    overhead_cost: money(overheadCost),
    unit_cost,

    unit_price,
    total_cost,
    total_price,
    profit_amount,
    tax_amount,

    effective_margin_percent:
      total_price > 0 ? round((profit_amount / total_price) * 100, 2) : 0,

    /*
     * Frações sobre o PREÇO, não sobre o custo — sobre o custo elas somariam
     * sempre 100% e não diriam nada. O guarda de preço zero cobre o caminho
     * "margem zero com custo zero", que a suíte de paridade exercita.
     */
    material_share_percent:
      unit_price > 0
        ? round(
            ((materialCost + wrapCost + hardwareCost + cradleCost) / unit_price) * 100,
            2,
          )
        : 0,

    labor_share_percent:
      unit_price > 0 ? round((laborCost / unit_price) * 100, 2) : 0,
  };
}

/**
 * Converte o CMV em preço de venda, aplicando lucro e impostos.
 *
 * markup: preço = custo × (1 + m), depois embute o imposto.
 *   "Acrescente X% sobre o custo." Não promete margem sobre a venda: 30% de
 *   markup entrega 23,1% de margem real (menos ainda depois do imposto).
 *
 * margin: preço = custo ÷ (1 − m − imposto).
 *   "Quero X% líquidos sobre o preço de venda." Margem e imposto saem do MESMO
 *   divisor porque ambos são fatias do preço final. Aplicá-los em sequência
 *   parece equivalente e não é: com 30% de margem e 8% de imposto, a margem
 *   real cairia para 27,6% — quebrando a promessa que a UI faz ao usuário.
 */
function applyProfitAndTax(
  cost: number,
  percent: number,
  mode: PricingMode,
  taxPercent: number,
): number {
  const tax = Math.max(taxPercent, 0);

  if (mode === "margin") {
    // Clamp para manter o divisor positivo: em 100% o preço tenderia ao
    // infinito e a tela mostraria "Infinity". O backend rejeita com erro
    // explícito; aqui apenas evitamos exibir lixo durante a digitação.
    const divisor = Math.max(1 - (percent + tax) / 100, 0.01);
    return cost / divisor;
  }

  const price = cost * (1 + percent / 100);

  if (tax <= 0) return price;

  // Imposto "por dentro": após recolher a alíquota, deve sobrar o pré-imposto.
  return price / (1 - Math.min(tax, 99.99) / 100);
}

/**
 * Arredondamento decimal compatível com o round() do PHP.
 *
 * Multiplicar por 10^n e arredondar falha nos limites exatos de meio centavo:
 * 81,585 é armazenado em binário como 81,58499999…, então `81.585 * 100`
 * resulta em 8158,4999… e arredonda para BAIXO — enquanto o PHP devolve
 * 81,59. Isso fazia o preview divergir do valor gravado pelo servidor.
 *
 * Deslocar a vírgula pela notação exponencial em STRING contorna o problema:
 * "81.585e2" é interpretado como exatamente 8158,5.
 */
function round(value: number, precision: number): number {
  if (!Number.isFinite(value)) return value;

  const sinal = value < 0 ? -1 : 1;
  const abs = Math.abs(value);

  // Números muito grandes/pequenos já vêm em notação exponencial e
  // quebrariam a concatenação; nesses casos o método aritmético basta.
  if (abs.toString().includes("e")) {
    const factor = 10 ** precision;
    return Math.round(value * factor) / factor;
  }

  const deslocado = Number(`${abs}e${precision}`);

  // Math.round arredonda 0,5 para cima; com o sinal aplicado por fora, o
  // resultado é "meio para longe do zero", igual ao PHP.
  return sinal * Number(`${Math.round(deslocado)}e${-precision}`);
}

/**
 * Valores unitários com 4 casas: uma embalagem pode custar R$ 0,0842 e ser
 * multiplicada por 50.000 unidades — arredondar para centavos aqui distorceria
 * o total em centenas de reais.
 */
function money(value: number, precision = 4): number {
  return round(value, precision);
}

/** Formatação monetária pt-BR, usada em toda a UI. */
export function formatCurrency(value: number, currency = "BRL", maxDigits = 2): string {
  /*
   * Um lucro exatamente zero saía como "−R$ 0,00".
   *
   * O resíduo da conta (preço − custo − imposto) chega aqui como −5,6e-14 ou
   * como o zero negativo do IEEE-754, e o Intl é fiel ao sinal. Na tela isso
   * vira um sinal de menos na frente de um zero — que lê como prejuízo, e
   * prejuízo de valor nenhum. Arredondar antes de formatar resolve: `|| 0`
   * captura −0 porque zero negativo é falsy.
   */
  const arredondado = Number(value.toFixed(maxDigits)) || 0;

  return new Intl.NumberFormat("pt-BR", {
    style: "currency",
    currency,
    maximumFractionDigits: maxDigits,
  }).format(arredondado);
}

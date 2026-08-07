import type {
  BoxModel,
  CostSettings,
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
export const ENGINE_VERSION = "1.0.0";

const MM2_PER_M2 = 1_000_000;
const MINUTES_PER_HOUR = 60;

/* ────────────────────────────────────────────────────────────────────────────
 * Geometria — espelha BlankCalculator.php
 * ──────────────────────────────────────────────────────────────────────────── */

const GLUE_FLAP_MM = 35;
const LID_CLEARANCE_MM = 2;
const LID_HEIGHT_RATIO = 0.35;
const SEAL_MM = 10;

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
  return model === "tray" || model === "tube";
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
  }
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
  //
  // A tampa é resolvida ANTES do plano de corte: o consumo de material precisa
  // refletir a tampa REAL, não a sugerida.
  const lid = resolveLidDimensions(
    spec.box_model,
    spec.width_mm,
    spec.height_mm,
    spec.depth_mm,
    material.thickness_mm ?? 0,
    spec,
  );

  const blank = blankDimensions(
    spec.box_model,
    spec.width_mm,
    spec.height_mm,
    spec.depth_mm,
    material.thickness_mm ?? 0,
    lid,
  );

  const netAreaPerUnit = (blank.width * blank.height) / MM2_PER_M2;

  // Desperdício incide sobre a ÁREA (aparas, refile, setup), não sobre o custo.
  const grossAreaPerUnit = netAreaPerUnit * (1 + spec.waste_percent / 100);
  const grossAreaTotal = grossAreaPerUnit * spec.quantity;

  // ── 2. Matéria-prima ──────────────────────────────────────────────────────
  const materialCost = grossAreaPerUnit * material.cost_per_m2;

  // ── 3. Mão de obra e operacional ──────────────────────────────────────────
  const hours = spec.production_minutes_per_unit / MINUTES_PER_HOUR;

  const laborCost = hours * settings.labor_hour_rate;
  const machineCost = hours * settings.machine_hour_rate;
  const energyCost = hours * settings.machine_power_kw * settings.energy_tariff_per_kwh;

  // ── 4. CMV ────────────────────────────────────────────────────────────────
  const directCost = materialCost + laborCost + machineCost + energyCost;
  const overheadCost = directCost * (settings.overhead_percent / 100);
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

    lid_width_mm: lid ? round(lid.widthMm, 2) : null,
    lid_depth_mm: lid ? round(lid.depthMm, 2) : null,
    lid_height_mm: lid ? round(lid.heightMm, 2) : null,

    material_cost: money(materialCost),
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
  return new Intl.NumberFormat("pt-BR", {
    style: "currency",
    currency,
    maximumFractionDigits: maxDigits,
  }).format(value);
}

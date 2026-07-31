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

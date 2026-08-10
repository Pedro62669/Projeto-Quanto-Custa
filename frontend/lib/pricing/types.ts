/**
 * Contrato compartilhado entre o motor local (preview) e a API.
 *
 * Os nomes em snake_case são intencionais: são exatamente os campos que o
 * Laravel devolve. Manter o mesmo vocabulário nas duas pontas elimina uma
 * camada de tradução — e a classe de bug que vem junto com ela.
 */

export type BoxModel =
  | "rsc"
  | "tray"
  | "sleeve"
  | "pouch"
  | "tube"
  | "drawer"
  | "mailer"
  | "rigid_telescopic"
  | "rigid_book"
  | "rigid_book_flap"
  | "rigid_magnet"
  | "rigid_magnet_side"
  | "rigid_magnet_wrap"
  /**
   * Modelo livre: sem geometria. O usuário mede as peças e o motor soma.
   *
   * É o único valor da união que não tem planificação — a UI mostra o editor de
   * peças no lugar da cena 3D. Ver `custom_parts` em QuoteSpecification.
   */
  | "free";
export type PricingMode = "markup" | "margin";

/** "un" = peça: ferragem contada, não medida (ímã, fecho, rebite). */
export type MaterialUnit = "m2" | "kg" | "un";

/** O papel de um material dentro da peça. ⚠️ Espelha o enum ComponentRole. */
export type ComponentRole = "structure" | "wrap" | "hardware" | "cradle";

/** Construções de berço interno. ⚠️ Espelha o enum CradleType. */
export type CradleType =
  | "foam"
  | "board_niche"
  | "paper_niche"
  | "paper_fold"
  | "divider_grid";

/**
 * O berço escolhido para a peça.
 *
 * `cost_per_unit` é R$/m² ou R$/m³ conforme o tipo — já normalizado pela API,
 * como todo valor que chega ao motor local. A grade só é lida em
 * `divider_grid`; nos outros tipos ela existe com 1×1 e não consome nada.
 */
export interface CradleSpec {
  type: CradleType;
  cost_per_unit: number;
  rows: number;
  columns: number;
  /** Altura do berço em fração da altura interna. 1 = até a boca da caixa. */
  height_ratio: number;
  strip_thickness_mm: number;
}

/**
 * Uma linha de ferragem na lista de materiais.
 *
 * Chega com o custo JÁ resolvido pela API — o motor local não consulta preço
 * de material, ele consome o número que o backend normalizou. Duas
 * implementações da mesma normalização seriam a divergência que a suíte de
 * paridade existe para impedir.
 */
export interface HardwareLine {
  cost_per_piece: number;
  quantity: number;
}

export interface Material {
  id: number;
  name: string;
  type: string;
  type_label: string;
  /** Custo já normalizado para R$/m² pelo backend. */
  cost_per_m2: number;
  default_waste_percent: number;
  thickness_mm: number | null;
  color_hex: string;
  texture_url: string | null;
  is_active: boolean;
}

/** Custos fixos vigentes — vêm de GET /api/pricing/parameters. */
export interface CostSettings {
  energy_tariff_per_kwh: number;
  machine_hour_rate: number;
  machine_power_kw: number;
  labor_hour_rate: number;
  overhead_percent: number;
  tax_percent: number;
  default_profit_margin_percent: number;
  currency: string;

  /**
   * Modo hora-empresa: o minuto calculado a partir das despesas fixas reais
   * substitui `labor_hour_rate` e zera `overhead_percent`.
   *
   * `company_minute_cost` vem PRONTO do backend porque depende de somar
   * `fixed_costs` e `equipment` — dados que o motor local não tem e nem
   * deveria ter. O preview usa o mesmo número que a API usou.
   *
   * Opcionais para não quebrar consumidor que ainda não os envia; ausentes,
   * o motor segue no regime de estimativa.
   */
  use_company_hour?: boolean;
  company_minute_cost?: number | null;
}

/* ────────────────────────────────────────────────────────────────────────────
 * Hora-empresa — Fase 2
 * ──────────────────────────────────────────────────────────────────────────── */

/** Os três cenários fixos. ⚠️ Espelha o enum EfficiencyScenario do PHP. */
export type EfficiencyPercent = 100 | 85 | 75;

/** Uma despesa fixa mensal, como o CRUD a devolve. */
export interface FixedCostItem {
  id: number;
  name: string;
  monthly_amount: number;
  is_active: boolean;
}

/** Uma máquina do parque, como o CRUD a devolve. */
export interface EquipmentItem {
  id: number;
  name: string;
  purchase_value: number;
  useful_life_months: number;
  monthly_depreciation: number;
  annual_depreciation: number;
}

/** Os parâmetros operacionais que o usuário controla na tela de custos. */
export interface CompanyHourParams {
  hours_per_day: number;
  days_per_month: number;
  efficiency_percent: EfficiencyPercent;
  include_depreciation: boolean;
  monthly_production_volume: number;
}

/** Um cenário de eficiência resolvido. */
export interface EfficiencyScenarioResult {
  efficiency_percent: EfficiencyPercent;
  label: string;
  description: string;
  productive_hours: number;
  hour_cost: number;
  minute_cost: number;
}

/** Saída do cálculo da hora-empresa — idêntica ao array do PHP. */
export interface CompanyHourBreakdown {
  parameters: CompanyHourParams;
  cost_base: {
    fixed_costs: number;
    depreciation: number;
    total: number;
  };
  monthly_hours: number;
  /** Quanto a depreciação do parque pesa em cada peça produzida. */
  depreciation_per_unit: number;
  active_scenario: EfficiencyScenarioResult;
  /** Sempre os três, do otimista ao conservador. */
  comparison: EfficiencyScenarioResult[];
}

/** Tudo que o usuário controla na tela da calculadora. */
export interface QuoteSpecification {
  material_id: number | null;
  box_model: BoxModel;
  width_mm: number;
  height_mm: number;
  depth_mm: number;
  quantity: number;
  waste_percent: number;
  production_minutes_per_unit: number;
  profit_margin_percent: number;
  pricing_mode: PricingMode;

  /**
   * Medidas da tampa informadas pelo usuário, em mm.
   *
   * null significa "automático": a medida acompanha a base. Cada eixo é
   * independente, então dá para fixar só a altura da tampa.
   */
  lid_width_mm: number | null;
  lid_depth_mm: number | null;
  lid_height_mm: number | null;

  /**
   * Lista de materiais — cartonagem rígida.
   *
   * `wrap_cost_per_m2` é o papel de revestimento, já normalizado para R$/m²
   * pelo backend (materiais cotados em kg passam pela gramatura lá). Null nos
   * modelos dobrados, onde o material é um só.
   */
  wrap_cost_per_m2?: number | null;

  /** Ímãs, fechos, fitas. Ausente ou vazio = sem ferragem. */
  hardware?: HardwareLine[];

  /** Berço de acomodação. Ausente ou null = caixa vazia. */
  cradle?: CradleSpec | null;

  /**
   * Peças medidas à mão — só no modelo livre.
   *
   * Ignoradas nos demais modelos, e de propósito: um RSC que chegasse com peças
   * somaria a planificação calculada MAIS os retângulos digitados, cobrando o
   * material duas vezes. O erro sairia plausível, que é o pior tipo.
   */
  custom_parts?: CustomPartLine[];
}

/**
 * Uma peça retangular do modelo livre. ⚠️ Espelha PricingInput::$customParts.
 *
 * Chega normalizada do backend: `cost_per_m2` e `waste_percent` já resolvidos a
 * partir do material da peça. O front NÃO recalcula nenhum dos dois — fazê-lo
 * seria reimplementar a conversão por gramatura, que é exatamente o que a suíte
 * de paridade existe para impedir.
 */
export interface CustomPartLine {
  /** "structure" (papelão) ou "wrap" (revestimento). */
  role: ComponentRole;
  cost_per_m2: number;
  /** Vem do material da peça, não do orçamento: cada insumo perde o seu. */
  waste_percent: number;
  width_mm: number;
  length_mm: number;
  /** Peças iguais POR CAIXA, não pelo lote. */
  quantity: number;
}

/** Saída do cálculo — idêntica ao PricingResult do PHP. */
export interface PricingBreakdown {
  area_m2_per_unit: number;
  area_m2_total: number;
  blank_width_mm: number;
  blank_height_mm: number;

  /** Área do revestimento por peça (m²); 0 fora da cartonagem rígida. */
  wrap_area_m2_per_unit: number;

  /** Área do berço por peça (m²); 0 em espuma, que é volumétrica. */
  cradle_area_m2_per_unit: number;

  /** Volume do berço por peça (m³); só espuma/EVA. */
  cradle_volume_m3_per_unit: number;

  /** Medidas físicas da tampa; null nos modelos sem peça separada. */
  lid_width_mm: number | null;
  lid_depth_mm: number | null;
  lid_height_mm: number | null;

  /** Estrutura: papelão cinza na rígida, a chapa única nas dobradas. */
  material_cost: number;

  /** Papel de revestimento; 0 fora da cartonagem rígida. */
  wrap_cost: number;

  /** Ímãs, fechos, fitas — cobrados por peça; 0 quando não há. */
  hardware_cost: number;

  /** Material do berço de acomodação; 0 quando não há. */
  cradle_cost: number;

  /** Minutos que o berço acrescenta — já somados na mão de obra. */
  cradle_minutes: number;

  labor_cost: number;
  machine_cost: number;
  energy_cost: number;
  overhead_cost: number;
  unit_cost: number;

  unit_price: number;
  total_cost: number;
  total_price: number;
  profit_amount: number;
  tax_amount: number;
  effective_margin_percent: number;
}

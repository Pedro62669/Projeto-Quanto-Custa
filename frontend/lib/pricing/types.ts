/**
 * Contrato compartilhado entre o motor local (preview) e a API.
 *
 * Os nomes em snake_case são intencionais: são exatamente os campos que o
 * Laravel devolve. Manter o mesmo vocabulário nas duas pontas elimina uma
 * camada de tradução — e a classe de bug que vem junto com ela.
 */

export type BoxModel = "rsc" | "tray" | "sleeve" | "pouch";
export type PricingMode = "markup" | "margin";
export type MaterialUnit = "m2" | "kg";

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
}

/** Saída do cálculo — idêntica ao PricingResult do PHP. */
export interface PricingBreakdown {
  area_m2_per_unit: number;
  area_m2_total: number;
  blank_width_mm: number;
  blank_height_mm: number;

  /** Medidas físicas da tampa; null nos modelos sem peça separada. */
  lid_width_mm: number | null;
  lid_depth_mm: number | null;
  lid_height_mm: number | null;

  material_cost: number;
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

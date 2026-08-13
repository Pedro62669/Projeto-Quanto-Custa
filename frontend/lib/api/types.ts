/**
 * Contratos de saída da API.
 *
 * snake_case porque são exatamente os campos que o Laravel devolve — manter o
 * mesmo vocabulário nas duas pontas elimina uma camada de tradução, e a classe
 * de bug que vem junto com ela. Os tipos do MOTOR de preço vivem em
 * `lib/pricing/types.ts`; aqui ficam os do resto do sistema.
 */

/* ── Sessão ─────────────────────────────────────────────────────────────── */

export interface SessionUserPayload {
  id: number;
  name: string;
  email: string;
  role: "admin" | "user";
  is_admin: boolean;
  email_verified: boolean;
  last_login_at: string | null;
}

/** Consumo de uma cota do plano. `limite` null = ilimitado. */
export interface QuotaSummary {
  usado: number;
  limite: number | null;
  restante: number | null;
  rotulo: string;
}

export interface AccountContext {
  user: SessionUserPayload;

  /**
   * Nulo para o admin de plataforma, que não pertence a empresa nenhuma. É o
   * mesmo `tenant_id` nulo que separa os dois papéis no servidor.
   */
  tenant: {
    id: number;
    name: string;
    legal_name: string | null;
    city: string | null;
    state: string | null;
    logo_url: string | null;
    is_active: boolean;
    acesso_liberado: boolean;
  } | null;

  plano: {
    /** VIGENTE, não contratado: é ele que manda nas cotas. */
    tipo: string;
    rotulo: string;
    contratado: string;
    situacao: string;
    trial_ends_at: string | null;
    subscription_ends_at: string | null;
  } | null;

  cotas: Record<string, QuotaSummary> | null;
}

export interface LoginResult {
  token: string;
  user: { id: number; name: string; email: string; role: "admin" | "user" };
}

/* ── Empresa ────────────────────────────────────────────────────────────── */

export interface Company {
  id: number;
  name: string;
  legal_name: string | null;
  document: string | null;
  email: string | null;
  whatsapp: string | null;
  phone: string | null;
  instagram: string | null;
  tiktok: string | null;
  facebook: string | null;
  website: string | null;
  postal_code: string | null;
  street: string | null;
  street_number: string | null;
  complement: string | null;
  district: string | null;
  city: string | null;
  state: string | null;
  logo_url: string | null;
}

/**
 * A empresa como o `GET /api/company` a devolve: os campos, mais o que falta.
 *
 * `pendencias_para_pdf` vem junto do registro em vez de num endpoint próprio
 * porque descreve ESTE retrato — pedir os dois separadamente abriria uma janela
 * em que a lista de pendências fala de uma versão que a tela já não mostra.
 */
export interface CompanyPayload extends Company {
  /** O que ainda falta para a proposta em PDF sair completa. */
  pendencias_para_pdf: string[];
}

export interface CompanyUser {
  id: number;
  name: string;
  email: string;
  role: "admin" | "user";
  is_active: boolean;
  last_login_at: string | null;
}

/* ── Catálogo ───────────────────────────────────────────────────────────── */

export type MaterialUnitValue = "m2" | "kg" | "un" | "m3";
export type GrainDirectionValue = "none" | "length" | "width";

/** O material como o CRUD de admin o devolve (inclui o preço de compra). */
export interface MaterialAdmin {
  id: number;
  name: string;
  type: string;
  type_label: string;
  description: string | null;
  cost_unit: MaterialUnitValue;
  cost_per_unit: number;
  grammage_kg_per_m2: number | null;
  cost_per_m2: number | null;
  is_area_based: boolean;
  default_waste_percent: number;
  thickness_mm: number | null;
  sheet_width_mm: number | null;
  sheet_length_mm: number | null;
  grain_direction: GrainDirectionValue;
  lot_quantity: number | null;
  lot_purchase_cost: number | null;
  lot_freight_cost: number | null;
  color_hex: string;
  texture_url: string | null;
  is_active: boolean;
}

/**
 * O que o formulário de material ENVIA.
 *
 * Separado de `MaterialAdmin` porque os dois lados são diferentes: a leitura
 * traz números já resolvidos pelo servidor (`cost_per_m2`, `is_area_based`), e
 * a escrita precisa poder dizer "não informado" — que é `null`, nunca zero.
 * Zero é um custo; null é a ausência dele, e confundir os dois precifica a
 * caixa como se o material fosse de graça.
 */
export interface MaterialPayload {
  name: string;
  type: string;
  description: string | null;
  cost_unit: MaterialUnitValue;
  cost_per_unit: number | null;
  grammage_kg_per_m2: number | null;
  lot_quantity: number | null;
  lot_purchase_cost: number | null;
  lot_freight_cost: number | null;
  sheet_width_mm: number | null;
  sheet_length_mm: number | null;
  grain_direction: GrainDirectionValue;
  default_waste_percent: number | null;
  thickness_mm: number | null;
  color_hex: string;
  texture_url: string | null;
  is_active: boolean;
}

export interface Client {
  id: number;
  name: string;
  cpf_cnpj: string | null;
  email: string | null;
  phone: string | null;
  state: string | null;
  city: string | null;
  address: string | null;
  is_active: boolean;
}

export interface Supplier {
  id: number;
  name: string;
  contact_name: string | null;
  phone: string | null;
  email: string | null;
  state: string | null;
  city: string | null;
  is_active: boolean;

  /**
   * O que este fornecedor vende.
   *
   * Só o suficiente para desenhar a etiqueta. O servidor recorta a três campos
   * de propósito: custo de compra e gramatura são informação de negociação, e
   * não têm o que fazer numa listagem de contatos.
   */
  materials: Array<{ id: number; name: string; type: string }>;
}

/**
 * O que se envia ao gravar um fornecedor.
 *
 * Difere da leitura num ponto: os materiais vão como lista de ids, não como
 * objetos. Omitir `material_ids` preserva o vínculo existente — quem manda só o
 * telefone não apaga o que o fornecedor vende.
 */
export interface SupplierPayload {
  name: string;
  contact_name: string | null;
  phone: string | null;
  email: string | null;
  state: string | null;
  city: string | null;
  is_active: boolean;
  material_ids: number[];
}

export interface Product {
  id: number;
  name: string;
  sku: string | null;
  cost_price: number | null;
  sale_price: number | null;
  stock_quantity: number | null;
  description: string | null;
  margin_percent?: number | null;
  is_active: boolean;
}

/* ── Custos ─────────────────────────────────────────────────────────────── */

export interface FixedCost {
  id: number;
  name: string;
  monthly_amount: number;
  is_active: boolean;
}

export interface Equipment {
  id: number;
  name: string;
  purchase_value: number;
  useful_life_months: number;
  monthly_depreciation: number;
  annual_depreciation: number;
}

export interface CostSettingVersion {
  id: number;
  energy_tariff_per_kwh: number;
  machine_hour_rate: number;
  machine_power_kw: number;
  labor_hour_rate: number;
  overhead_percent: number;
  tax_percent: number;
  default_profit_margin_percent: number;
  currency: string;
  effective_from: string;
  use_company_hour: boolean;
  company_hours_per_day: number | null;
  company_days_per_month: number | null;
  company_efficiency_percent: number | null;
  company_includes_depreciation: boolean;
  monthly_production_volume: number | null;
}

/* ── Orçamentos ─────────────────────────────────────────────────────────── */

export type QuoteStatusValue = "draft" | "sent" | "approved" | "rejected";

export interface QuoteListItem {
  id: number;
  reference: string;
  status: QuoteStatusValue;
  client: { name: string; email: string | null; document: string | null };
  specification: {
    width_mm: number;
    height_mm: number;
    depth_mm: number;
    box_model: string;
    quantity: number;
    material?: { id: number; name: string; color_hex: string };
  };
  parameters: {
    waste_percent: number;
    production_minutes_per_unit: number;
    profit_margin_percent: number;
    pricing_mode: string;
  };
  costs: Record<string, number>;
  pricing: {
    unit_price: number;
    total_cost: number;
    total_price: number;
    profit_amount: number;
  };
  area: { per_unit_m2: number; total_m2: number };
  notes: string | null;
  created_at: string | null;
  snapshot?: Record<string, unknown> | null;
}

/** Uma peça posicionada na folha — coordenadas prontas para virar SVG. */
export interface NestedPart {
  name: string;
  x: number;
  y: number;
  width_mm: number;
  length_mm: number;
  rotated: boolean;
}

export interface SheetLayout {
  sheet_id: number;
  width_mm: number;
  length_mm: number;
  efficiency_percent: number;
  parts: NestedPart[];
}

export interface MaterialCuttingPlan {
  material: { id: number; name: string };
  sheet: { width_mm: number; length_mm: number };
  kerf_mm: number;
  grain_direction: GrainDirectionValue;
  rotation_allowed: boolean;
  quoted_waste_percent: number;
  real_waste_percent: number;
  /** Positiva: a empresa perde mais chapa do que cobra. */
  divergence_percent: number;
  sheets_needed: number;
  sheets_estimated: number;
  efficiency_percent: number;
  waste_percent: number;
  truncated: boolean;
  pieces_planned: number;
  pieces_total: number;
  layouts: SheetLayout[];
}

export interface CutPiece {
  name: string;
  width_mm: number;
  height_mm: number;
  quantity: number;
}

export interface TechnicalSheet {
  quote: {
    id: number;
    reference: string;
    client_name: string;
    status: string;
    created_at: string | null;
    notes: string | null;
  };
  specification: {
    box_model: string;
    box_model_label: string;
    width_mm: number;
    height_mm: number;
    depth_mm: number;
    thickness_mm: number;
    quantity: number;
    material: string | null;
  };
  cut_template: {
    structure: CutPiece[];
    wrap: CutPiece[];
    notes?: string[];
    [key: string]: unknown;
  };
  picking_list: Array<{
    material_role: string;
    material_label: string;
    piece: string;
    size: string;
    per_unit: number;
    total: number;
  }>;
  cutting_plan: {
    by_material: MaterialCuttingPlan[];
    warnings: string[];
    notes: string[];
  };
}

/* ── Financeiro ─────────────────────────────────────────────────────────── */

export type TransactionTypeValue = "entry" | "exit";
export type TransactionCategoryValue =
  | "quote_sale"
  | "product_sale"
  | "material_purchase"
  | "fixed_cost"
  | "other";

export interface Transaction {
  id: number;
  type: TransactionTypeValue;
  category: TransactionCategoryValue;
  amount: number;
  description: string;
  transaction_date: string;
  client_id: number | null;
  supplier_id: number | null;
  client?: { id: number; name: string } | null;
  supplier?: { id: number; name: string } | null;
  installments?: Installment[];
}

/**
 * O que o formulário de lançamento envia.
 *
 * Inclui `installments` e `first_due_date`, que NÃO são campos da transação:
 * são instruções de como o servidor deve montar as parcelas. Uma compra em 3×
 * é um lançamento com três vencimentos, não três lançamentos.
 */
export interface TransactionPayload {
  type: TransactionTypeValue;
  category: TransactionCategoryValue;
  amount: number | null;
  description: string;
  transaction_date: string;
  client_id?: number | null;
  supplier_id?: number | null;
  installments?: number | null;
  first_due_date?: string | null;
}

export interface Installment {
  id: number;
  transaction_id: number;
  number: number;
  total: number;
  amount: number;
  due_date: string;
  payment_date: string | null;
  status: "pending" | "completed";
  transaction?: {
    id: number;
    type: TransactionTypeValue;
    category: TransactionCategoryValue;
    description: string;
  };
}

export interface FinancialDashboard {
  period: { month: number; year: number };
  revenue: { realized: number; projected: number; [key: string]: number };
  expenses: { realized: number; projected: number; [key: string]: number };
  net_realized: number;
  break_even: Record<string, number>;
  revenue_distribution: Array<{ label: string; amount: number; percent: number }>;
  overdue: { count: number; amount: number; [key: string]: number };
  [key: string]: unknown;
}

/* ── Assinatura ─────────────────────────────────────────────────────────── */

/**
 * Um plano como o servidor o descreve.
 *
 * O mesmo formato chega por dois caminhos — `/plans`, que a página de vendas lê
 * sem token, e `/subscription`, que a tela do assinante lê com ele. É um tipo
 * só porque no servidor também é: ver `PlanType::toArray()`.
 */
export interface Plano {
  tipo: string;
  rotulo: string;
  mensalidade: number;
  pago: boolean;
  /** Null = ilimitado, o mesmo contrato do resumo de cotas. */
  limites: {
    materiais: number | null;
    clientes: number | null;
    orcamentos_por_mes: number | null;
  };
}

/** A tabela de preços pública, com as promessas que a landing anuncia. */
export interface Vitrine {
  planos: Plano[];
  dias_de_teste: number;
  dias_de_arrependimento: number;
}

export interface SubscriptionContext {
  plano: {
    tipo: string;
    rotulo: string;
    mensalidade: number;
    contratado: string;
    situacao: string;
    situacao_rotulo: string;
  };
  acesso_liberado: boolean;
  em_teste: boolean;
  trial_ends_at: string | null;
  subscription_ends_at: string | null;
  assinatura: {
    id: number;
    started_at: string;
    current_period_ends_at: string | null;
    amount: number;
    /** Direito de arrependimento do CDC — informado, nunca escondido. */
    arrependimento_disponivel: boolean;
    arrependimento_ate: string;
  } | null;
  cotas: Record<string, QuotaSummary>;

  /**
   * Os planos oferecidos, com preço e limites — vindos do servidor.
   *
   * A interface já manteve a própria tabela e mostrou R$ 99,90 num cartão
   * enquanto o cabeçalho da mesma página dizia R$ 149,90. Preço de assinatura
   * é informação do servidor; a tela só a transporta.
   */
  planos_disponiveis: Plano[];
}

/* ── Plataforma ─────────────────────────────────────────────────────────── */

export interface PlatformTenant {
  id: number;
  name: string;
  document: string | null;
  plan_type: string;
  plan_status: string;
  is_active: boolean;
  trial_ends_at: string | null;
  created_at: string | null;
  [key: string]: unknown;
}

export interface PlatformUser {
  id: number;
  name: string;
  email: string;
  role: string;
  is_active: boolean;
  tenant?: { id: number; name: string } | null;
  last_login_at: string | null;
}

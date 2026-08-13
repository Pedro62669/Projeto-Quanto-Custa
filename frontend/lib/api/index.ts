import type {
  Material,
  PricingBreakdown,
  QuoteSpecification,
} from "@/lib/pricing/types";

import { crud, crudSimples, objectUrl, query, request, upload } from "./client";
import type {
  AccountContext,
  Client,
  Company,
  CompanyPayload,
  CompanyUser,
  CostSettingVersion,
  Equipment,
  FinancialDashboard,
  FixedCost,
  Installment,
  LoginResult,
  MaterialAdmin,
  MaterialPayload,
  PlatformTenant,
  PlatformUser,
  Product,
  QuoteListItem,
  SubscriptionContext,
  Supplier,
  SupplierPayload,
  TechnicalSheet,
  Transaction,
  TransactionPayload,
} from "./types";

export { ApiError, TOKEN_STORAGE_KEY, baixarArquivo, toPage } from "./client";
export type { ListParams, Page } from "./client";
export { buscarVitrine } from "./vitrine";
export * from "./types";

/* ── Parâmetros de cálculo ──────────────────────────────────────────────── */

export interface PricingParameters {
  currency: string;
  default_profit_margin_percent: number;
  tax_percent: number;
  box_models: Array<{
    value: string;
    label: string;
    default_production_minutes: number;
  }>;
  limits: {
    dimension_mm: { min: number; max: number };
    quantity: { min: number; max: number };
  };
}

/**
 * A API do sistema, agrupada por domínio.
 *
 * Namespaces em vez de sessenta métodos soltos: `api.quotes.approve(id)` diz de
 * que assunto se trata antes de dizer o que faz, e o agrupamento é o que
 * permite achar o método certo sem abrir o arquivo do backend.
 *
 * Todo dinheiro é derivado no SERVIDOR. Nenhum método aqui envia preço — quando
 * um payload parece conter valores (o orçamento, por exemplo), eles são
 * recalculados na API e o que veio do navegador é descartado.
 */
export const api = {
  /* ── Sessão e conta ──────────────────────────────────────────────────── */
  auth: {
    login: (email: string, password: string) =>
      request<{ data: LoginResult }>("/login", {
        method: "POST",
        body: JSON.stringify({ email, password, device_name: "web" }),
      }).then((r) => r.data),

    logout: () => request<void>("/logout", { method: "POST" }),

    me: () => request<{ data: AccountContext }>("/me").then((r) => r.data),

    register: (payload: {
      empresa: string;
      nome: string;
      email: string;
      password: string;
      password_confirmation: string;
      documento?: string;
    }) =>
      request<{ data: LoginResult }>("/register", {
        method: "POST",
        body: JSON.stringify(payload),
      }).then((r) => r.data),

    /**
     * A resposta é NEUTRA por decisão do backend: e-mail existente e
     * inexistente devolvem a mesma mensagem, para o formulário não virar um
     * detector de quem tem conta.
     */
    forgotPassword: (email: string) =>
      request<{ message: string }>("/password/email", {
        method: "POST",
        body: JSON.stringify({ email }),
      }),

    resetPassword: (payload: {
      token: string;
      email: string;
      password: string;
      password_confirmation: string;
    }) =>
      request<{ message: string }>("/password/reset", {
        method: "POST",
        body: JSON.stringify(payload),
      }),

    resendVerification: () =>
      request<{ message: string }>("/email/verification-notification", {
        method: "POST",
      }),

    /** Exclusão da conta (LGPD art. 18). Exige a senha, e é irreversível. */
    deleteAccount: (payload: { password: string; confirmacao: "EXCLUIR" }) =>
      request<{ message: string }>("/account", {
        method: "DELETE",
        body: JSON.stringify(payload),
      }),
  },

  /* ── Calculadora ─────────────────────────────────────────────────────── */
  pricing: {
    parameters: () =>
      request<{ data: PricingParameters }>("/pricing/parameters").then((r) => r.data),

    /** Materiais ATIVOS, com o custo já normalizado. É o que a calculadora usa. */
    materials: () => request<{ data: Material[] }>("/materials").then((r) => r.data),

    /**
     * Confirmação do cálculo pelo servidor.
     *
     * O `signal` é essencial: as chamadas saem em debounce enquanto o usuário
     * digita, e sem abortar as anteriores uma resposta atrasada pode chegar
     * depois de uma mais recente e sobrescrever a tela com números velhos.
     */
    simulate: (spec: QuoteSpecification, signal?: AbortSignal) =>
      request<{ data: PricingBreakdown & { currency: string; engine_version: string } }>(
        "/quotes/simulate",
        { method: "POST", body: JSON.stringify(spec), signal },
      ).then((r) => r.data),
  },

  /* ── Orçamentos ──────────────────────────────────────────────────────── */
  quotes: {
    ...crud<QuoteListItem>("/quotes"),

    create: (
      spec: QuoteSpecification,
      client: { client_name: string; client_email?: string; notes?: string },
    ) =>
      request<{ data: { id: number; reference: string } }>("/quotes", {
        method: "POST",
        body: JSON.stringify({ ...spec, ...client }),
      }).then((r) => r.data),

    /** Aprovar gera as parcelas no livro-caixa — não é só mudar um rótulo. */
    approve: (id: number, payload: { installments?: number; first_due_date?: string } = {}) =>
      request<{ data: unknown; message?: string }>(`/quotes/${id}/approve`, {
        method: "POST",
        body: JSON.stringify(payload),
      }),

    promoteClient: (id: number) =>
      request<{ data: Client; message?: string }>(`/quotes/${id}/promote-client`, {
        method: "POST",
      }),

    technicalSheet: (id: number) =>
      request<{ data: TechnicalSheet }>(`/quotes/${id}/technical-sheet`).then(
        (r) => r.data,
      ),

    /** O PDF é baixado com o token no cabeçalho — ver `baixarArquivo()`. */
    pdfPath: (id: number) => `/quotes/${id}/download-pdf`,
  },

  /* ── Cadastros ───────────────────────────────────────────────────────── */
  /*
   * Paginado: o índice de admin usa `paginate()`, ao contrário de custos fixos
   * e equipamentos. Tratá-lo como lista simples devolveria só as 25 primeiras
   * linhas — e a busca por nome no cliente deixaria de encontrar o resto sem
   * dar nenhum sinal.
   */
  materials: crud<MaterialAdmin, MaterialPayload>("/admin/materials"),
  clients: crud<Client>("/clients"),
  suppliers: crud<Supplier, SupplierPayload>("/suppliers"),
  products: crud<Product>("/products"),

  /* ── Empresa ─────────────────────────────────────────────────────────── */
  company: {
    get: () => request<{ data: CompanyPayload }>("/company").then((r) => r.data),

    update: (payload: Partial<Company>) =>
      request<{ data: CompanyPayload }>("/company", {
        method: "PUT",
        body: JSON.stringify(payload),
      }).then((r) => r.data),

    uploadLogo: (file: File) => {
      const form = new FormData();
      form.append("logo", file);

      return upload<{ data: { logo_url: string } }>("/company/logo", form);
    },

    removeLogo: () => request<{ message: string }>("/company/logo", { method: "DELETE" }),

    /**
     * O logotipo como URL de objeto, para caber num `<img src>`.
     *
     * O arquivo fica em disco PRIVADO e a rota exige token — um `<img>` apontado
     * direto para `/api/company/logo` faria uma navegação sem cabeçalho de
     * autorização e receberia 401. Guardar o logo num disco público resolveria
     * o `<img>` e tornaria a lista de clientes do SaaS enumerável por URL, que é
     * um preço bem mais alto.
     *
     * Quem chama precisa revogar a URL ao desmontar.
     */
    logoObjectUrl: () => objectUrl("/company/logo"),

    users: crudSimples<CompanyUser>("/admin/users"),
  },

  /* ── Custos e hora-empresa ───────────────────────────────────────────── */
  costs: {
    /** Todas as versões publicadas — o histórico é auditoria, não lixo. */
    versions: () =>
      request<{ data: CostSettingVersion[] }>("/admin/cost-settings").then((r) => r.data),

    /** A versão vigente, com o custo do minuto já resolvido pelo servidor. */
    current: () =>
      request<{ data: CostSettingVersion & { company_minute_cost: number | null } }>(
        "/admin/cost-settings/current",
      ).then((r) => r.data),

    /** Publicar CRIA uma versão nova; orçamentos gravados não mudam de valor. */
    publish: (payload: Record<string, unknown>) =>
      request<{ data: CostSettingVersion; message?: string }>("/admin/cost-settings", {
        method: "POST",
        body: JSON.stringify(payload),
      }),

    fixed: crudSimples<FixedCost>("/admin/fixed-costs"),

    /** O index devolve `meta.total_mensal` junto — a soma que vira hora-empresa. */
    fixedWithTotal: () =>
      request<{ data: FixedCost[]; meta: Record<string, number> }>("/admin/fixed-costs"),

    equipment: crudSimples<Equipment>("/admin/equipment"),

    depreciationImpact: (monthlyProduction: number) =>
      request<{ data: Record<string, number> }>(
        `/admin/equipment/depreciation-impact${query({ monthly_production: monthlyProduction })}`,
      ).then((r) => r.data),

    companyHour: (params: Record<string, string | number | boolean | undefined> = {}) =>
      request<{ data: unknown }>(`/admin/company-hour${query(params)}`).then((r) => r.data),
  },

  /* ── Financeiro ──────────────────────────────────────────────────────── */
  finance: {
    dashboard: (month?: number, year?: number) =>
      request<{ data: FinancialDashboard }>(
        `/financial/dashboard${query({ month, year })}`,
      ).then((r) => r.data),

    transactions: crud<Transaction, TransactionPayload>("/transactions"),
    installments: crud<Installment>("/installments"),

    settle: (id: number, paymentDate?: string) =>
      request<{ data: Installment }>(`/installments/${id}/settle`, {
        method: "POST",
        body: JSON.stringify({ payment_date: paymentDate }),
      }).then((r) => r.data),

    unsettle: (id: number) =>
      request<{ data: Installment }>(`/installments/${id}/settle`, {
        method: "DELETE",
      }).then((r) => r.data),
  },

  /* ── Assinatura ──────────────────────────────────────────────────────── */
  billing: {
    subscription: () =>
      request<{ data: SubscriptionContext }>("/subscription").then((r) => r.data),

    checkout: (planType: string) =>
      request<{ data: { url: string; expires_at?: string } }>("/subscriptions/checkout", {
        method: "POST",
        body: JSON.stringify({ plan_type: planType }),
      }).then((r) => r.data),

    cancel: (payload: { password: string }) =>
      request<{ data: unknown; message?: string }>("/subscriptions/cancel", {
        method: "POST",
        body: JSON.stringify(payload),
      }),
  },

  /* ── Operação do SaaS ────────────────────────────────────────────────── */
  platform: {
    dashboard: () =>
      request<{ data: Record<string, unknown> }>("/platform/dashboard").then((r) => r.data),

    tenants: crud<PlatformTenant>("/platform/tenants"),

    updatePlan: (id: number, payload: { plan_type: string; plan_status?: string }) =>
      request<{ data: PlatformTenant; message?: string }>(
        `/platform/tenants/${id}/plan`,
        { method: "PATCH", body: JSON.stringify(payload) },
      ),

    suspend: (id: number, ativo: boolean) =>
      request<{ data: PlatformTenant; message?: string }>(
        `/platform/tenants/${id}/suspend`,
        { method: "PATCH", body: JSON.stringify({ is_active: ativo }) },
      ),

    users: crud<PlatformUser>("/platform/users"),

    toggleUser: (id: number, ativo: boolean) =>
      request<{ message?: string }>(`/platform/users/${id}/active`, {
        method: "PATCH",
        body: JSON.stringify({ is_active: ativo }),
      }),

    forceLogout: (id: number) =>
      request<{ message?: string }>(`/platform/users/${id}/force-logout`, {
        method: "POST",
      }),

    sendPasswordReset: (id: number) =>
      request<{ message?: string }>(`/platform/users/${id}/password-reset`, {
        method: "POST",
      }),
  },
};

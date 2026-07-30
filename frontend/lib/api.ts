import type {
  CostSettings,
  Material,
  PricingBreakdown,
  QuoteSpecification,
} from "./pricing/types";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api";

/**
 * Chave do token no localStorage. Definida aqui, e não em auth.ts, porque este
 * módulo é o que a lê em toda requisição — auth.ts a importa de volta. Evita
 * a string mágica duplicada em dois arquivos que podem divergir.
 */
export const TOKEN_STORAGE_KEY = "auth_token";

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    /** Erros de validação do Laravel: { campo: [mensagens] } */
    public readonly errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = "ApiError";
  }
}

/**
 * Cliente HTTP mínimo para a API Laravel (Sanctum, token Bearer).
 *
 * Deliberadamente sem biblioteca externa: o app faz meia dúzia de chamadas e
 * o fetch nativo cobre o caso com menos superfície de manutenção.
 */
async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const token =
    typeof window !== "undefined" ? localStorage.getItem(TOKEN_STORAGE_KEY) : null;

  const response = await fetch(`${API_URL}${path}`, {
    ...init,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...init.headers,
    },
  });

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));

    // Token expirado ou revogado: derruba a sessão local e manda para o login.
    // Tratado aqui, no único ponto por onde toda requisição passa, em vez de
    // repetido em cada chamador.
    if (response.status === 401 && typeof window !== "undefined" && path !== "/login") {
      localStorage.removeItem(TOKEN_STORAGE_KEY);
      window.location.href = "/login";
    }

    throw new ApiError(
      body.message ?? `Falha na requisição (HTTP ${response.status})`,
      response.status,
      body.errors ?? {},
    );
  }

  return response.status === 204 ? (undefined as T) : response.json();
}

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

export interface LoginResult {
  token: string;
  user: { id: number; name: string; email: string; role: "admin" | "user" };
}

export const api = {
  login: (email: string, password: string) =>
    request<{ data: LoginResult }>("/login", {
      method: "POST",
      body: JSON.stringify({ email, password, device_name: "web" }),
    }).then((r) => r.data),

  logout: () => request<void>("/logout", { method: "POST" }),

  materials: () => request<{ data: Material[] }>("/materials").then((r) => r.data),

  parameters: () =>
    request<{ data: PricingParameters }>("/pricing/parameters").then((r) => r.data),

  costSettings: () =>
    request<{ data: CostSettings }>("/admin/cost-settings/current").then((r) => r.data),

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

  saveQuote: (
    spec: QuoteSpecification,
    client: { client_name: string; client_email?: string; notes?: string },
  ) =>
    request<{ data: { id: number; reference: string } }>("/quotes", {
      method: "POST",
      body: JSON.stringify({ ...spec, ...client }),
    }).then((r) => r.data),
};

/**
 * Cliente HTTP para a API Laravel (Sanctum, token Bearer).
 *
 * Deliberadamente sem biblioteca externa: o fetch nativo cobre o caso com menos
 * superfície de manutenção, e o app inteiro passa por esta função — que é onde
 * o 401 derruba a sessão uma vez só, em vez de repetido em cada chamador.
 */

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

export function authHeader(): Record<string, string> {
  const token =
    typeof window !== "undefined" ? localStorage.getItem(TOKEN_STORAGE_KEY) : null;

  return token ? { Authorization: `Bearer ${token}` } : {};
}

export async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch(`${API_URL}${path}`, {
    ...init,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...authHeader(),
      ...init.headers,
    },
  });

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));

    // Token expirado ou revogado: derruba a sessão local e manda para o login.
    // Tratado aqui, no único ponto por onde toda requisição passa.
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

/**
 * Envio de arquivo (multipart).
 *
 * Separado de `request()` porque o `Content-Type` NÃO pode ser definido à mão:
 * o navegador precisa gerar o boundary do multipart, e um cabeçalho fixo faria
 * o Laravel receber um corpo que não sabe separar.
 */
export async function upload<T>(path: string, form: FormData): Promise<T> {
  const response = await fetch(`${API_URL}${path}`, {
    method: "POST",
    headers: { Accept: "application/json", ...authHeader() },
    body: form,
  });

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));

    throw new ApiError(
      body.message ?? `Falha no envio (HTTP ${response.status})`,
      response.status,
      body.errors ?? {},
    );
  }

  return response.json();
}

/**
 * Baixa um arquivo protegido por token.
 *
 * Um `<a href>` simples não serve: o navegador não manda o cabeçalho
 * `Authorization` numa navegação comum, e o download voltaria 401. Buscar como
 * blob e disparar o clique num link temporário é o que preserva a autenticação
 * — e é a razão de o PDF não ser um link no HTML.
 */
export async function baixarArquivo(path: string, nomeSugerido: string): Promise<void> {
  const response = await fetch(`${API_URL}${path}`, {
    headers: { Accept: "application/pdf", ...authHeader() },
  });

  if (!response.ok) {
    throw new ApiError(`Falha ao baixar o arquivo (HTTP ${response.status})`, response.status);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);

  const link = document.createElement("a");
  link.href = url;
  link.download = nomeSugerido;
  document.body.appendChild(link);
  link.click();
  link.remove();

  // Sem o revoke o blob fica retido em memória até a aba fechar.
  URL.revokeObjectURL(url);
}

/**
 * Busca um recurso protegido e devolve uma URL de objeto para exibi-lo.
 *
 * Mesmo motivo do download: uma tag `<img>` navega sem o cabeçalho
 * `Authorization`, então um arquivo servido por rota autenticada só chega à
 * tela por este caminho. Quem chama é responsável por `URL.revokeObjectURL`.
 */
export async function objectUrl(path: string): Promise<string> {
  const response = await fetch(`${API_URL}${path}`, {
    headers: { ...authHeader() },
  });

  if (!response.ok) {
    throw new ApiError(`Falha ao carregar o arquivo (HTTP ${response.status})`, response.status);
  }

  return URL.createObjectURL(await response.blob());
}

/** Monta a query string ignorando vazios — `?status=&page=2` não filtra nada. */
export function query(params: Record<string, string | number | boolean | undefined | null> = {}): string {
  const busca = new URLSearchParams();

  for (const [chave, valor] of Object.entries(params)) {
    if (valor === undefined || valor === null || valor === "") continue;
    busca.set(chave, String(valor));
  }

  const texto = busca.toString();

  return texto ? `?${texto}` : "";
}

/* ── Paginação ──────────────────────────────────────────────────────────── */

/** Uma página de resultados, no formato que a interface consome. */
export interface Page<T> {
  items: T[];
  page: number;
  lastPage: number;
  perPage: number;
  total: number;
}

/**
 * Normaliza as DUAS paginações que a API devolve.
 *
 * `Resource::collection($paginator)` produz `{data, meta:{current_page,…}}`;
 * `response()->json($paginator)` produz os mesmos campos no NÍVEL RAIZ. As duas
 * convivem no backend (orçamentos usam a primeira, clientes e lançamentos a
 * segunda), e a diferença é irrelevante para quem desenha uma tabela.
 *
 * A tradução mora aqui, num lugar só, em vez de cada tela testar qual formato
 * chegou — que é como uma delas acabaria lendo `undefined` e mostrando lista
 * vazia sem erro nenhum.
 */
export function toPage<T>(payload: unknown): Page<T> {
  const bruto = (payload ?? {}) as Record<string, unknown>;
  const meta = (bruto.meta ?? bruto) as Record<string, unknown>;

  const numero = (valor: unknown, padrao: number): number =>
    typeof valor === "number" ? valor : Number(valor ?? padrao) || padrao;

  const items = Array.isArray(bruto.data) ? (bruto.data as T[]) : [];

  return {
    items,
    page: numero(meta.current_page, 1),
    lastPage: numero(meta.last_page, 1),
    perPage: numero(meta.per_page, items.length),
    total: numero(meta.total, items.length),
  };
}

/* ── Fábricas de CRUD ───────────────────────────────────────────────────── */

/** Filtros de listagem. `page` e `per_page` são universais; o resto é do domínio. */
export type ListParams = Record<string, string | number | boolean | undefined | null>;

/**
 * CRUD sobre um recurso paginado.
 *
 * A repetição de cinco métodos por cadastro é o tipo de código que envelhece
 * mal quando copiado: uma correção no tratamento de erro precisaria ser feita
 * em oito lugares. Aqui ela é feita uma vez.
 */
export function crud<T, P = Partial<T>>(base: string) {
  return {
    list: (params: ListParams = {}) =>
      request<unknown>(`${base}${query(params)}`).then((r) => toPage<T>(r)),

    get: (id: number | string) =>
      request<{ data: T }>(`${base}/${id}`).then((r) => r.data),

    create: (payload: P) =>
      request<{ data: T }>(base, {
        method: "POST",
        body: JSON.stringify(payload),
      }).then((r) => r.data),

    update: (id: number | string, payload: P) =>
      request<{ data: T }>(`${base}/${id}`, {
        method: "PUT",
        body: JSON.stringify(payload),
      }).then((r) => r.data),

    remove: (id: number | string) =>
      request<{ message?: string }>(`${base}/${id}`, { method: "DELETE" }),
  };
}

/**
 * Mesma coisa para os recursos que devolvem a lista INTEIRA.
 *
 * Materiais, custos fixos e equipamentos não paginam de propósito: são dezenas
 * de linhas, não milhares, e a tela precisa deles todos de uma vez para
 * alimentar seletores.
 */
export function crudSimples<T, P = Partial<T>>(base: string) {
  const paginado = crud<T, P>(base);

  return {
    ...paginado,
    list: (params: ListParams = {}) =>
      request<{ data: T[] }>(`${base}${query(params)}`).then((r) => r.data),
  };
}

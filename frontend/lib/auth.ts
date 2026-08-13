import { api, TOKEN_STORAGE_KEY } from "./api";

/**
 * Sessão do usuário no navegador.
 *
 * O token vive em localStorage, não em cookie httpOnly. É a escolha coerente
 * com a arquitetura headless (Bearer token entre origens distintas), mas tem
 * um custo real: fica legível por JavaScript e, portanto, exposto a XSS.
 *
 * Mitigações adotadas: o token expira em 7 dias (definido no AuthController) e
 * é revogável no logout. Se o produto passar a lidar com dados mais sensíveis,
 * o caminho é mover para cookie httpOnly + Sanctum stateful, o que exige
 * frontend e API sob o mesmo domínio.
 */

const TOKEN_KEY = TOKEN_STORAGE_KEY;

/** Exportada porque useSession() a lê diretamente via useSyncExternalStore. */
export const USER_STORAGE_KEY = "auth_user";
const USER_KEY = USER_STORAGE_KEY;

export interface SessionUser {
  id: number;
  name: string;
  email: string;
  role: "admin" | "user";
}

export const session = {
  get token(): string | null {
    if (typeof window === "undefined") return null;
    return localStorage.getItem(TOKEN_KEY);
  },

  get user(): SessionUser | null {
    if (typeof window === "undefined") return null;

    const raw = localStorage.getItem(USER_KEY);
    if (!raw) return null;

    try {
      return JSON.parse(raw) as SessionUser;
    } catch {
      // Payload corrompido (edição manual, versão antiga do formato): tratar
      // como "sem sessão" em vez de propagar um erro de parse pela aplicação.
      return null;
    }
  },

  get isAuthenticated(): boolean {
    return this.token !== null;
  },

  async login(email: string, password: string): Promise<SessionUser> {
    const { token, user } = await api.auth.login(email, password);

    this.adopt(token, user);

    return user;
  },

  /**
   * Adota uma sessão que o servidor já emitiu.
   *
   * O cadastro público devolve o token junto com a conta criada: mandar a
   * pessoa para o login logo depois de ela ter digitado a senha seria pedir a
   * mesma coisa duas vezes. A gravação passa por aqui, e não pelo localStorage
   * direto na página, para as chaves ficarem definidas num lugar só.
   */
  adopt(token: string, user: SessionUser): void {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  },

  async logout(): Promise<void> {
    // Revoga no servidor antes de limpar localmente. Se a chamada falhar
    // (offline, token já expirado), a sessão local é apagada mesmo assim —
    // deixar o usuário preso numa tela por causa da rede seria pior.
    try {
      await api.auth.logout();
    } finally {
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem(USER_KEY);
    }
  },
};

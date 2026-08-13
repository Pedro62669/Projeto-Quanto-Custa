import { create } from "zustand";

import { api, type AccountContext } from "@/lib/api";

/**
 * O contexto da sessão — usuário, empresa, plano e cotas.
 *
 * Store própria, e não mais um pedaço da calculadora: o dado é de toda a
 * aplicação (o cabeçalho mostra a empresa, a barra lateral decide o que exibir
 * pelo papel, a tela de assinatura lê as cotas), e mantê-lo dentro da
 * `useQuoteStore` obrigaria qualquer tela a montar a calculadora inteira para
 * saber quem está logado.
 *
 * Carregada uma vez pela casca. `reload()` existe para depois das ações que
 * mudam o retrato — trocar de plano, salvar o perfil da empresa, gravar um
 * orçamento que consome cota.
 */
interface AccountState {
  account: AccountContext | null;
  loading: boolean;
  error: string | null;

  load: () => Promise<void>;
  reload: () => Promise<void>;
  clear: () => void;
}

export const useAccount = create<AccountState>()((set, get) => ({
  account: null,
  loading: true,
  error: null,

  async load() {
    // Já carregado: a casca monta em toda navegação, e refazer a chamada a cada
    // troca de rota seria uma requisição por clique sem nada de novo para dizer.
    if (get().account !== null) {
      set({ loading: false });

      return;
    }

    await get().reload();
  },

  async reload() {
    set({ loading: true, error: null });

    try {
      set({ account: await api.auth.me(), loading: false });
    } catch (erro) {
      set({
        loading: false,
        error: erro instanceof Error ? erro.message : "Falha ao carregar a sessão.",
      });
    }
  },

  clear() {
    set({ account: null, loading: false, error: null });
  },
}));

/** Admin da empresa: quem pode mexer em cadastro, custo e assinatura. */
export const selectIsAdmin = (s: AccountState) => s.account?.user.is_admin === true;

/** Admin de PLATAFORMA: sem empresa vinculada, opera o SaaS. */
export const selectIsPlatformAdmin = (s: AccountState) =>
  s.account !== null && s.account.tenant === null;

export const selectCompanyName = (s: AccountState) => s.account?.tenant?.name ?? null;

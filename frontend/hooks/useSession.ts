"use client";

import { useMemo, useSyncExternalStore } from "react";
import { USER_STORAGE_KEY, type SessionUser } from "@/lib/auth";

/**
 * Lê a sessão do localStorage de forma segura para SSR.
 *
 * Por que useSyncExternalStore e não useState + useEffect: localStorage é uma
 * store EXTERNA ao React. Lê-la num efeito e jogar no estado dispara uma
 * renderização em cascata (e o React Compiler reclama, com razão). Este hook é
 * a API que o React oferece exatamente para esse caso.
 *
 * Benefício adicional: ao assinar o evento `storage`, sair da conta em uma aba
 * derruba a sessão nas outras automaticamente.
 */

function subscribe(onStoreChange: () => void): () => void {
  window.addEventListener("storage", onStoreChange);
  return () => window.removeEventListener("storage", onStoreChange);
}

/**
 * Devolve a STRING crua, não o objeto já parseado.
 *
 * getSnapshot precisa ser referencialmente estável: retornar um objeto novo a
 * cada chamada faria o React detectar mudança em todo render e entrar em laço
 * infinito. A desserialização acontece depois, memoizada.
 */
function getSnapshot(): string | null {
  return localStorage.getItem(USER_STORAGE_KEY);
}

/** No servidor não há sessão — o valor real chega na hidratação. */
function getServerSnapshot(): string | null {
  return null;
}

export function useSession(): SessionUser | null {
  const raw = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);

  return useMemo(() => {
    if (!raw) return null;

    try {
      return JSON.parse(raw) as SessionUser;
    } catch {
      return null;
    }
  }, [raw]);
}

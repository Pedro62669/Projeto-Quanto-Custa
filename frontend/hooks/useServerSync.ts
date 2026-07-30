import { useEffect, useRef } from "react";
import { useQuoteStore } from "@/store/useQuoteStore";

/**
 * Mantém o cálculo do servidor sincronizado com a especificação, em debounce.
 *
 * Isolado num hook porque é uma preocupação de INFRAESTRUTURA (quando falar com
 * a rede), não de apresentação. A página não deveria conter lógica de timers.
 *
 * O debounce é o que torna viável recalcular a cada tecla: sem ele, digitar
 * "1500" dispararia quatro requisições, três delas descartáveis.
 */
export function useServerSync(delayMs = 400) {
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    // subscribeWithSelector: reage apenas a mudanças em `spec`. Assinar o store
    // inteiro re-dispararia a sincronização a cada `isSyncing: true` — um laço
    // infinito de requisições.
    const unsubscribe = useQuoteStore.subscribe(
      (state) => state.spec,
      () => {
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(() => {
          void useQuoteStore.getState().syncWithServer();
        }, delayMs);
      },
    );

    return () => {
      if (timer.current) clearTimeout(timer.current);
      unsubscribe();
    };
  }, [delayMs]);
}

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
 *
 * ## `ativo`
 *
 * Existe por causa de uma CORRIDA, e ela aparecia na tela.
 *
 * Sem assinatura o servidor recusa `simulate` com 403, e a calculadora entra em
 * modo demonstração — mas só depois de `/api/me` responder qual é o plano.
 * Enquanto essa resposta não chega, o bootstrap já mexeu na especificação e o
 * debounce já foi agendado: se ele disparasse primeiro, a conta sem plano
 * recebia um 403 que a tela mostrava como erro vermelho ao lado da demonstração
 * funcionando. O produto se apresentava quebrado no primeiro acesso.
 *
 * A trava dentro de `syncWithServer` não resolve sozinha: ela lê `demoMode`, que
 * também só existe depois da mesma resposta. Quem sabe esperar é a página.
 */
export function useServerSync({ ativo = true }: { ativo?: boolean } = {}, delayMs = 500) {
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (!ativo) return;

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
  }, [ativo, delayMs]);
}

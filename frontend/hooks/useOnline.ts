import { useSyncExternalStore } from "react";

/**
 * O navegador está conectado?
 *
 * Via `useSyncExternalStore` porque `navigator.onLine` é uma store EXTERNA ao
 * React — o mesmo motivo pelo qual useSession lê o localStorage assim. Com
 * useState + useEffect haveria uma renderização com o valor errado antes do
 * efeito corrigir, e o indicador piscaria "offline" a cada montagem.
 *
 * O snapshot do servidor é `true`: a página é pré-renderizada estaticamente e
 * lá não existe navegador. Assumir conectado evita divergência de hidratação —
 * e se estiver offline de verdade, o primeiro evento corrige em milissegundos.
 *
 * Vale o que o navegador sabe, que é pouco: `onLine` false significa "sem
 * interface de rede", mas true não promete que a API responde. Por isso ele
 * complementa o estado de sincronização, em vez de substituí-lo.
 */
export function useOnline(): boolean {
  return useSyncExternalStore(subscribe, () => navigator.onLine, () => true);
}

function subscribe(onChange: () => void): () => void {
  window.addEventListener("online", onChange);
  window.addEventListener("offline", onChange);

  return () => {
    window.removeEventListener("online", onChange);
    window.removeEventListener("offline", onChange);
  };
}

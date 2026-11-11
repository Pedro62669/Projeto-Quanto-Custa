"use client";

import { Loader2 } from "lucide-react";
import { useShallow } from "zustand/react/shallow";

import { useQuoteStore } from "@/store/useQuoteStore";

/**
 * "calculando" / "em dia com o servidor" — o estado da sincronia da calculadora.
 *
 * ## Por que mora num arquivo só dele
 *
 * Para poder ser carregado TARDE. Ele lê a `useQuoteStore`, e a store importa o
 * motor de precificação: 66 KB de matemática de cartonagem que só a calculadora
 * executa. Enquanto isto vivia dentro do `AppHeader`, o import estático levava o
 * motor para o pacote da casca — e ele descia em toda tela do sistema, incluindo
 * as que nunca calculam nada.
 *
 * Separado, o `AppHeader` o traz com `next/dynamic` e o pacote só é buscado
 * quando o usuário de fato abre `/calculadora`.
 *
 * O aviso de OFFLINE ficou de fora de propósito: ele vale em qualquer tela, e é
 * justamente quando a rede cai que não se pode depender de um pedaço de
 * JavaScript ainda por baixar.
 */
export function IndicadorDeCalculo() {
  const { isSyncing, isConfirmed } = useQuoteStore(
    useShallow((s) => ({ isSyncing: s.isSyncing, isConfirmed: s.confirmed !== null })),
  );

  if (isSyncing) {
    return (
      <span
        aria-live="polite"
        className="flex items-center gap-1.5 text-[11px] text-muted-foreground"
      >
        <Loader2 className="size-3.5 animate-spin" />
        calculando
      </span>
    );
  }

  if (!isConfirmed) return null;

  return (
    <span
      aria-live="polite"
      className="flex items-center gap-1.5 text-[11px] text-muted-foreground"
    >
      <span className="size-1.5 rounded-full bg-emerald-500" aria-hidden />
      em dia com o servidor
    </span>
  );
}

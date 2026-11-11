"use client";

import { useEffect, useRef, type CSSProperties, type ReactNode } from "react";

import { cn } from "@/lib/utils";

/**
 * Faz o conteúdo entrar em cena quando a rolagem chega nele.
 *
 * O estado ESCONDIDO é do CSS (`[data-revelar]` em globals.css), e este
 * componente só marca `data-visivel` quando o bloco cruza a janela. Essa divisão
 * é o que mantém a página honesta nos três casos em que animação costuma
 * custar caro:
 *
 *  - sem JavaScript, um `<noscript>` no layout da vitrine anula a regra e o
 *    texto aparece inteiro — o buscador e o leitor de tela nunca dependeram dela,
 *    porque opacidade zero não tira nada do HTML;
 *  - com `prefers-reduced-motion`, a regra nem se aplica: quem pediu menos
 *    movimento recebe a página parada, não uma versão "suave" dela;
 *  - e o observador se desliga depois da primeira entrada. Reanimar a cada
 *    passada transforma a leitura em espetáculo, e quem volta para reler um
 *    preço não quer esperar ele chegar de novo.
 *
 * `atraso` escalona irmãos (cartões de uma grade, passos de uma lista) sem que
 * cada um precise do próprio observador coordenado: todos disparam juntos e o
 * CSS cuida da fila.
 */
export function Revelar({
  children,
  className,
  atraso = 0,
  como: Como = "div",
}: {
  children: ReactNode;
  className?: string;
  /** Atraso da entrada, em milissegundos. */
  atraso?: number;
  como?: "div" | "li" | "section";
}) {
  const ref = useRef<HTMLElement | null>(null);

  useEffect(() => {
    const alvo = ref.current;
    if (!alvo) return;

    // Navegador sem IntersectionObserver é raro, mas o custo de errar aqui é a
    // página em branco: na dúvida, mostra tudo.
    if (typeof IntersectionObserver === "undefined") {
      alvo.dataset.visivel = "";

      return;
    }

    const observador = new IntersectionObserver(
      ([entrada]) => {
        if (!entrada?.isIntersecting) return;

        alvo.dataset.visivel = "";
        observador.disconnect();
      },
      // A margem negativa embaixo segura a entrada até o bloco ter subido um
      // pouco: disparar no primeiro pixel faz a animação acontecer na borda da
      // tela, onde ninguém está olhando.
      { rootMargin: "0px 0px -8% 0px", threshold: 0.08 },
    );

    observador.observe(alvo);

    return () => observador.disconnect();
  }, []);

  return (
    <Como
      // O ref é o mesmo para as três tags; o TypeScript só não sabe unir os
      // tipos de elemento de um componente polimórfico.
      ref={ref as never}
      data-revelar=""
      className={cn(className)}
      style={atraso ? ({ "--atraso": `${atraso}ms` } as CSSProperties) : undefined}
    >
      {children}
    </Como>
  );
}

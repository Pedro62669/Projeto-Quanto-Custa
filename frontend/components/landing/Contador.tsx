"use client";

import { useEffect, useRef } from "react";

/**
 * Um número que sobe até o valor quando entra na tela.
 *
 * O valor FINAL é o que o servidor renderiza — é ele que o buscador indexa e o
 * que aparece se o JavaScript não rodar. A contagem acontece por cima, direto no
 * `textContent`: sessenta atualizações de estado por segundo fariam o React
 * reconciliar o cartão inteiro a cada quadro para trocar três dígitos.
 *
 * `formatar` não é prop de propósito. Uma função não atravessa a fronteira
 * servidor → cliente, e a página de vendas é renderizada no servidor; por isso
 * o formato é escolhido por nome e montado aqui dentro.
 */
export function Contador({
  valor,
  formato,
  duracao = 1100,
  className,
}: {
  valor: number;
  formato: "moeda" | "percentual";
  /** Duração da contagem, em milissegundos. */
  duracao?: number;
  className?: string;
}) {
  const ref = useRef<HTMLSpanElement>(null);

  useEffect(() => {
    const alvo = ref.current;
    if (!alvo) return;

    // Quem pediu menos movimento fica com o número que já está na tela.
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    if (typeof IntersectionObserver === "undefined") return;

    let quadro = 0;

    const observador = new IntersectionObserver(
      ([entrada]) => {
        if (!entrada?.isIntersecting) return;
        observador.disconnect();

        const inicio = performance.now();

        const passo = (agora: number) => {
          const t = Math.min((agora - inicio) / duracao, 1);

          // Desacelera no fim: os últimos centavos chegam devagar, que é quando
          // o olho consegue acompanhar e o número "assenta".
          const suave = 1 - Math.pow(1 - t, 3);

          alvo.textContent = FORMATOS[formato](valor * suave);

          if (t < 1) quadro = requestAnimationFrame(passo);
        };

        quadro = requestAnimationFrame(passo);
      },
      { threshold: 0.4 },
    );

    observador.observe(alvo);

    return () => {
      observador.disconnect();
      cancelAnimationFrame(quadro);
    };
  }, [valor, formato, duracao]);

  return (
    // O número é formatado pelo Intl nos dois lados, e o ICU do Node e o do
    // navegador podem discordar num espaço (comum vs. não separável). Uma
    // diferença dessas não é defeito de conteúdo — o valor é o mesmo.
    <span ref={ref} className={className} suppressHydrationWarning>
      {FORMATOS[formato](valor)}
    </span>
  );
}

const FORMATOS = {
  moeda: (n: number) =>
    n.toLocaleString("pt-BR", { style: "currency", currency: "BRL" }),
  percentual: (n: number) =>
    `${n.toLocaleString("pt-BR", { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`,
} as const;

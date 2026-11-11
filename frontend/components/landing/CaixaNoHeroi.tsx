"use client";

import { useEffect, useRef, useState } from "react";
import dynamic from "next/dynamic";
import Image from "next/image";

import poster from "@/public/capturas/caixa-heroi.png";

/**
 * A caixa 3D da primeira dobra — pôster primeiro, 3D depois.
 *
 * ## A decisão que este componente teve de vencer
 *
 * O docblock de `PlanoDeCorteIlustrado` registra uma decisão CONTRÁRIA a pôr o
 * visualizador real aqui, e a razão continua boa: `three` e `@react-three/fiber`
 * passam de meio megabyte, e carregá-los na primeira tela adiaria justamente a
 * imagem que precisa aparecer antes de a pessoa decidir sair.
 *
 * A decisão de produto mudou — a caixa girando é o que mais convence, e sem
 * teste grátis a vitrine precisa convencer sozinha. Mas a preocupação registrada
 * não foi ignorada: ela foi SATISFEITA.
 *
 *  1. **O pôster é o que pinta.** Uma captura real do mesmo visualizador, servida
 *     como imagem otimizada. Ela aparece no primeiro quadro e é ela que conta
 *     como LCP — o JavaScript de 3D não participa da métrica.
 *  2. **O 3D entra depois**, por `next/dynamic`, e só quando a página já está
 *     ociosa e a dobra está visível. Quem só passa os olhos e sai nunca baixa
 *     three.js.
 *  3. **Nada de rede além do JavaScript.** O ambiente de luz é gerado na
 *     própria cena (ver BoxViewer), sem baixar mapa HDR nenhum.
 *
 * ## Quem fica só com o pôster
 *
 * `prefers-reduced-motion` — quem pediu menos movimento não deve receber uma
 * cena que gira — e `saveData`, que é o navegador dizendo que a pessoa está
 * pagando por megabyte. Nos dois casos a página não perde nada: a imagem mostra
 * exatamente a mesma caixa.
 */
const BoxViewer = dynamic(
  () => import("@/components/three/BoxViewer").then((m) => m.BoxViewer),
  { ssr: false, loading: () => null },
);

/**
 * A caixa do pôster.
 *
 * Precisa ser a MESMA da captura, senão a troca salta na tela. Ao regerar o
 * pôster (`npm run capturas`), estes números e os do script têm de continuar
 * iguais — é uma caixa rígida de tampa solta em proporção de caixa de presente.
 */
const CAIXA = {
  widthMm: 200,
  heightMm: 100,
  depthMm: 150,
  boxModel: "rigid_telescopic" as const,
  thicknessMm: 3,
  colorHex: "#C8A06A",
};

export function CaixaNoHeroi() {
  const [mostrar3D, setMostrar3D] = useState(false);
  const [pronto, setPronto] = useState(false);
  const alvo = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const reduzMovimento = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    /*
     * `saveData` não existe em todo navegador (Safari e Firefox não o expõem).
     * O encadeamento opcional é o que impede um TypeError em vez de um
     * carregamento — e, na dúvida, o padrão é carregar.
     */
    const economizando =
      (navigator as Navigator & { connection?: { saveData?: boolean } }).connection
        ?.saveData === true;

    if (reduzMovimento || economizando) return;

    const elemento = alvo.current;
    if (!elemento) return;

    /*
     * Três condições, e as três precisam acontecer: a dobra visível, o navegador
     * ocioso, e um tempo mínimo de permanência.
     *
     * O tempo mínimo foi acrescentado depois de MEDIR. A primeira versão tinha
     * só as duas primeiras, e um visitante que fechava a aba em 0,7 s já havia
     * baixado 1740 KB — porque numa conexão rápida o navegador fica ocioso quase
     * imediatamente, e "ocioso" acabava significando "agora". A postergação não
     * protegia ninguém.
     *
     * ESPERA_MINIMA é uma aposta sobre quem está do outro lado: quem sai antes
     * disso estava passando os olhos e não devia pagar meio megabyte por uma
     * caixa que nem chegou a olhar; quem fica além disso está lendo, e recebe a
     * caixa viva antes de terminar o primeiro parágrafo. Nesse intervalo a tela
     * não fica vazia nem errada — mostra o pôster, que é a MESMA caixa.
     */
    const ESPERA_MINIMA = 2000;

    let cancelado = false;

    const observador = new IntersectionObserver(
      (entradas) => {
        if (!entradas.some((e) => e.isIntersecting)) return;

        observador.disconnect();

        window.setTimeout(() => {
          if (cancelado) return;

          const agenda =
            window.requestIdleCallback ?? ((fn: () => void) => window.setTimeout(fn, 200));

          agenda(() => {
            if (!cancelado) setMostrar3D(true);
          });
        }, ESPERA_MINIMA);
      },
      { rootMargin: "120px" },
    );

    observador.observe(elemento);

    return () => {
      cancelado = true;
      observador.disconnect();
    };
  }, []);

  return (
    <div
      ref={alvo}
      className="relative aspect-[4/3] overflow-hidden rounded-xl border border-border bg-gradient-to-b from-muted/40 to-muted/10"
    >
      {/*
        O pôster NÃO é desmontado quando o 3D entra — fica por baixo.

        Removê-lo no instante do `mostrar3D` deixaria um buraco pelos quadros que
        o Canvas leva para compilar os shaders e enquadrar a peça. Por baixo, ele
        cobre a emenda; por cima do 3D não há nada para cobrir.
      */}
      <Image
        src={poster}
        alt="Caixa rígida de tampa solta, vista em três quartos, desenhada em 3D pelo quantoCusta"
        priority
        sizes="(min-width: 1024px) 560px, 100vw"
        className={`absolute inset-0 size-full object-contain transition-opacity duration-500 ${
          pronto ? "opacity-0" : "opacity-100"
        }`}
      />

      {mostrar3D && (
        <div
          className={`absolute inset-0 transition-opacity duration-500 ${
            pronto ? "opacity-100" : "opacity-0"
          }`}
        >
          <BoxViewer
            {...CAIXA}
            onPronto={() => setPronto(true)}
            className="size-full"
          />
        </div>
      )}
    </div>
  );
}

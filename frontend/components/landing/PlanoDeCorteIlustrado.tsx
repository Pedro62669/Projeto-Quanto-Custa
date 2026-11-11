/**
 * A ilustração do herói: uma chapa com as peças encaixadas e a sobra hachurada.
 *
 * É SVG desenhado à mão, e não o visualizador 3D real, por duas razões. A
 * primeira é peso: `three` e `@react-three/fiber` somam mais de meio megabyte de
 * JavaScript, e carregá-los na primeira tela adiaria justamente a imagem que
 * precisa aparecer antes de a pessoa decidir sair. A segunda é que este desenho
 * não é decoração — é a saída literal do módulo de plano de corte, com peça
 * girada, sentido de fibra e sobra, que é o argumento que a página faz.
 *
 * Os números vêm do arranjo abaixo e conferem entre si: as áreas das peças
 * somadas contra a área da chapa dão a perda anunciada. Uma ilustração que
 * mostrasse 12% de sobra com metade da chapa vazia venderia o produto mentindo
 * sobre a única conta que ele existe para acertar.
 */

import type { CSSProperties } from "react";

import { Contador } from "./Contador";

/** Peças em milímetros, dentro de uma chapa de 1000 × 700 mm. */
const CHAPA = { largura: 1000, altura: 700 };

const PECAS = [
  { x: 0, y: 0, l: 340, a: 280, rotulo: "Fundo" },
  { x: 350, y: 0, l: 340, a: 280, rotulo: "Tampa" },
  { x: 700, y: 0, l: 295, a: 135, rotulo: "Lateral" },
  { x: 700, y: 145, l: 295, a: 135, rotulo: "Lateral" },
  { x: 0, y: 290, l: 245, a: 355, rotulo: "Frente" },
  { x: 255, y: 290, l: 245, a: 355, rotulo: "Trás" },
  { x: 510, y: 290, l: 235, a: 355, rotulo: "Topo", girada: true },
  { x: 760, y: 290, l: 235, a: 355, rotulo: "Base", girada: true },
];

/**
 * O ritmo da entrada, em milissegundos.
 *
 * As peças caem na chapa uma a uma, na ordem em que o módulo de corte as
 * colocaria — é a animação contando o que o produto faz, e não um enfeite por
 * cima dele. Só depois de todas pousarem a lâmina passa: cortar antes de
 * arranjar seria a ordem errada.
 */
const PRIMEIRA_PECA = 350;
const ENTRE_PECAS = 95;
const LAMINA = PRIMEIRA_PECA + PECAS.length * ENTRE_PECAS + 250;

const atraso = (ms: number) => ({ "--atraso": `${ms}ms` }) as CSSProperties;

/** Perda: o que da chapa não virou peça. Calculada, não escrita. */
const AREA_USADA = PECAS.reduce((total, p) => total + p.l * p.a, 0);
const PERDA = 1 - AREA_USADA / (CHAPA.largura * CHAPA.altura);

export function PlanoDeCorteIlustrado() {
  return (
    <figure className="overflow-hidden rounded-2xl border border-border bg-card shadow-elevado">
      {/* A barra de título da janela: situa a figura como TELA do produto, e
          não como diagrama solto — é o plano de corte que a ficha técnica
          mostra, com o mesmo nome que ele tem lá. */}
      <div className="flex items-center justify-between gap-3 border-b border-border/70 bg-muted/50 px-4 py-2.5">
        <span className="flex items-center gap-2 text-xs font-medium">
          <span className="size-2 rounded-full bg-brand" aria-hidden />
          Plano de corte
        </span>
        <span className="font-mono text-[0.7rem] text-muted-foreground tabular-nums">
          chapa 1 de 1
        </span>
      </div>

      <div className="p-4">
        <svg
          viewBox={`-12 -12 ${CHAPA.largura + 24} ${CHAPA.altura + 24}`}
          className="w-full"
          role="img"
          aria-label={`Plano de corte: oito peças encaixadas numa chapa de ${CHAPA.largura} por ${CHAPA.altura} milímetros, com ${(PERDA * 100).toFixed(1).replace(".", ",")} por cento de sobra`}
        >
          <defs>
            {/* A hachura da sobra: convenção de desenho técnico para "área que
                não vira produto". Some no plano de fundo em vez de competir com
                as peças. */}
            <pattern
              id="sobra"
              width="10"
              height="10"
              patternUnits="userSpaceOnUse"
              patternTransform="rotate(45)"
            >
              <line x1="0" y1="0" x2="0" y2="10" stroke="currentColor" strokeWidth="2.5" />
            </pattern>
          </defs>

          {/* A chapa inteira, hachurada: o que as peças não cobrirem fica à
              vista como sobra, sem precisar desenhar a sobra peça por peça. */}
          <rect
            width={CHAPA.largura}
            height={CHAPA.altura}
            className="text-destructive/25"
            fill="url(#sobra)"
          />
          <rect
            width={CHAPA.largura}
            height={CHAPA.altura}
            fill="none"
            className="stroke-foreground/25"
            strokeWidth="3"
          />

          {PECAS.map((peca, indice) => (
            <g
              key={indice}
              className="peca"
              style={atraso(PRIMEIRA_PECA + indice * ENTRE_PECAS)}
            >
              {/* Kraft claro, e não branco: a peça é papelão, e é a cor que a
                  separa da chapa por baixo sem depender só do contorno. */}
              <rect
                x={peca.x}
                y={peca.y}
                width={peca.l}
                height={peca.a}
                className="fill-kraft-suave stroke-foreground/45"
                strokeWidth="3"
                rx="4"
              />

              {/* O sentido de fibra, em traço fino. Peça girada tem fibra
                  cruzada — e é por isso que ela precisa aparecer no desenho:
                  girar por encaixe pode custar rigidez. */}
              {Array.from({ length: 4 }, (_, i) => {
                const passo = (peca.girada ? peca.l : peca.a) / 5;
                const deslocamento = passo * (i + 1);

                return peca.girada ? (
                  <line
                    key={i}
                    x1={peca.x + deslocamento}
                    y1={peca.y + 10}
                    x2={peca.x + deslocamento}
                    y2={peca.y + peca.a - 10}
                    className="stroke-foreground/15"
                    strokeWidth="2"
                  />
                ) : (
                  <line
                    key={i}
                    x1={peca.x + 10}
                    y1={peca.y + deslocamento}
                    x2={peca.x + peca.l - 10}
                    y2={peca.y + deslocamento}
                    className="stroke-foreground/15"
                    strokeWidth="2"
                  />
                );
              })}

              <text
                x={peca.x + peca.l / 2}
                y={peca.y + peca.a / 2 + 9}
                textAnchor="middle"
                className="fill-foreground/70"
                style={{ fontSize: 26, fontWeight: 500 }}
              >
                {peca.rotulo}
              </text>
            </g>
          ))}

          {/*
            A lâmina: uma passada da esquerda para a direita, depois some.

            `--curso` é a largura da chapa em unidades do SVG — dentro de um
            <svg> o `px` do CSS é a unidade do viewBox, então a linha atravessa
            exatamente a chapa em qualquer tamanho de tela.
          */}
          <line
            className="lamina stroke-brand"
            style={{ ...atraso(LAMINA), "--curso": `${CHAPA.largura}px` } as CSSProperties}
            x1="0"
            y1="-10"
            x2="0"
            y2={CHAPA.altura + 10}
            strokeWidth="5"
            strokeLinecap="round"
            opacity="0"
          />
        </svg>
      </div>

      <figcaption className="flex flex-wrap items-center gap-x-5 gap-y-1 border-t border-border/70 px-4 py-3 text-xs text-muted-foreground">
        <span>
          Chapa <strong className="font-medium text-foreground tabular-nums">1000 × 700 mm</strong>
        </span>
        <span>
          8 peças ·{" "}
          <strong className="font-medium text-foreground tabular-nums">2 giradas</strong>
        </span>
        <span>
          Perda real{" "}
          {/* Conta até o valor: o número que a planilha chuta é o que aqui se mede. */}
          <strong className="font-medium text-destructive tabular-nums">
            <Contador valor={PERDA * 100} formato="percentual" duracao={1400} />
          </strong>
        </span>
      </figcaption>
    </figure>
  );
}

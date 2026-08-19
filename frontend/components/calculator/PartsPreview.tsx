"use client";

import { useShallow } from "zustand/react/shallow";

import { selectCustomParts, useQuoteStore } from "@/store/useQuoteStore";
import type { CustomPartInput } from "@/lib/pricing/types";

/**
 * Prévia das peças do modelo livre — em escala, no lugar da cena 3D.
 *
 * O 3D não tem o que renderizar aqui: não existe geometria a derivar de largura,
 * altura e profundidade, existe uma lista de retângulos que o usuário mediu. Um
 * placeholder dizendo "sem prévia" desperdiçaria metade da tela; desenhar as
 * peças EM ESCALA, umas ao lado das outras, entrega a verificação que o 3D dava
 * nos outros modelos — a de que o que foi digitado é o que se tinha em mente.
 *
 * É o erro de digitação que ela pega: 2000 mm no lugar de 200 aparece na hora
 * como uma peça dez vezes maior que as vizinhas, muito antes de virar folha
 * cortada errada.
 *
 * NÃO é plano de corte. O arranjo aqui só compara tamanhos; onde cada peça cai
 * na chapa é o que a ficha técnica responde, com a folha real do material e a
 * espessura da lâmina descontada.
 */
export function PartsPreview() {
  const { parts, materials } = useQuoteStore(
    useShallow((s) => ({ parts: selectCustomParts(s), materials: s.materials })),
  );

  const desenhaveis = parts.filter((p) => p.width_mm > 0 && p.length_mm > 0);

  if (desenhaveis.length === 0) {
    return (
      <div className="flex h-full w-full items-center justify-center p-8 text-center">
        <p className="max-w-xs text-sm text-muted-foreground">
          Informe a medida das peças ao lado para vê-las em escala.
        </p>
      </div>
    );
  }

  const layout = arruma(desenhaveis);

  const corDoMaterial = (part: CustomPartInput): string =>
    materials.find((m) => m.id === part.material_id)?.color_hex ?? "#C8A06A";

  return (
    <div className="flex h-full w-full flex-col p-4">
      <header className="mb-2 flex items-baseline justify-between">
        <h2 className="text-sm font-semibold">Peças em escala</h2>
        <span className="text-xs text-muted-foreground">
          {desenhaveis.length} {desenhaveis.length === 1 ? "peça" : "peças"} · comparação
          de tamanho
        </span>
      </header>

      <svg
        viewBox={`0 0 ${layout.largura} ${layout.altura}`}
        preserveAspectRatio="xMidYMid meet"
        className="min-h-0 flex-1"
        role="img"
        aria-label={`Prévia em escala de ${desenhaveis.length} peças`}
      >
        {layout.pecas.map(({ part, x, y }) => (
          <g key={part.id}>
            <rect
              x={x}
              y={y}
              width={part.width_mm}
              height={part.length_mm}
              rx={layout.fonte * 0.3}
              fill={corDoMaterial(part)}
              fillOpacity={part.role === "wrap" ? 0.35 : 0.75}
              stroke="currentColor"
              strokeOpacity={0.35}
              strokeWidth={layout.largura / 500}
              // Tracejado no revestimento: ele não é peça estrutural, é a pele
              // que cobre outra peça — e o traço diz isso sem precisar de legenda.
              strokeDasharray={part.role === "wrap" ? `${layout.fonte / 2}` : undefined}
            />

            {/* O rótulo só cabe em peça grande o bastante. Numa peça pequena ele
                sairia por cima das vizinhas e atrapalharia justamente a
                comparação de tamanho que o desenho existe para fazer. */}
            <RotuloDaPeca part={part} x={x} y={y} fonte={layout.fonte} />
          </g>
        ))}
      </svg>

      <p className="mt-2 text-center text-[11px] text-muted-foreground">
        Comparação de tamanho, não plano de corte. Como as peças se encaixam na
        folha do material, com perda real, sai na ficha técnica.
      </p>
    </div>
  );
}

/**
 * Quanto da peça o texto pode ocupar. O resto é respiro contra a borda.
 */
const OCUPACAO = 0.84;

/** Altura de uma linha, em múltiplos da fonte. */
const ENTRELINHA = 1.25;

/**
 * Largura média de um caractere, em múltiplos da fonte.
 *
 * O mono é mais largo que o sans, e a medida é sempre mono — usar o maior dos
 * dois para dimensionar evita o caso em que o nome cabe e o "200 × 200" abaixo
 * dele estoura a peça.
 */
const LARGURA_DO_CARACTERE = 0.62;

/**
 * Piso da fonte, em fração da global. Abaixo disso o texto vira sujeira: ele
 * ainda está lá, mas ninguém lê — e uma linha ilegível ocupa espaço sem
 * informar, que é pior que linha nenhuma.
 */
const FONTE_MINIMA = 0.45;

/**
 * O rótulo dentro da peça: nome em cima, medida embaixo.
 *
 * O NOME é o que identifica a peça para quem a desenhou — "fundo", "tampa",
 * "lateral longa" — e é ele que a ficha técnica leva para a bancada. Sem ele,
 * num projeto de seis retângulos parecidos, duas peças de 200 × 200 ficavam
 * indistinguíveis: dava para conferir o tamanho e não para saber qual era qual.
 *
 * ## A fonte se ajusta à peça, e o descarte é o último recurso
 *
 * A primeira versão usava a fonte global e cortava linhas quando não cabiam.
 * Numa tira de 200 × 50 isso apagava o nome — justamente a peça que mais
 * precisa dele, porque é a que menos se reconhece pela forma.
 *
 * Agora o texto encolhe até caber, nas duas direções: a altura limita pelo
 * número de linhas, a largura pela linha mais longa. Só quando nem no piso de
 * legibilidade couber é que uma linha sai, e sai na ordem inversa da
 * importância — primeiro a quantidade, depois o nome, e a medida por último,
 * porque ela é a razão de o desenho existir.
 */
function RotuloDaPeca({
  part,
  x,
  y,
  fonte,
}: {
  part: CustomPartInput;
  x: number;
  y: number;
  fonte: number;
}) {
  const centroX = x + part.width_mm / 2;
  const nome = part.name.trim();

  // Da mais importante para a menos: é nesta ordem que elas serão descartadas,
  // de trás para a frente.
  const candidatas = [
    { texto: `${part.width_mm} × ${part.length_mm}`, classe: "font-mono", opacidade: 1 },
    ...(nome !== ""
      ? [{ texto: nome, classe: "font-sans font-medium", opacidade: 1 }]
      : []),
    ...(part.quantity > 1
      ? [{ texto: `×${part.quantity}`, classe: "font-mono", opacidade: 0.7 }]
      : []),
  ];

  /*
   * Tenta com todas as linhas e vai largando a menos importante enquanto a
   * fonte necessária ficar abaixo do piso.
   */
  let escolhidas = candidatas;
  let tamanho = 0;

  while (escolhidas.length > 0) {
    const maiorTexto = Math.max(...escolhidas.map((l) => l.texto.length));

    const porLargura =
      (part.width_mm * OCUPACAO) / (maiorTexto * LARGURA_DO_CARACTERE);
    const porAltura = (part.length_mm * OCUPACAO) / (escolhidas.length * ENTRELINHA);

    tamanho = Math.min(fonte, porLargura, porAltura);

    if (tamanho >= fonte * FONTE_MINIMA) break;

    escolhidas = escolhidas.slice(0, -1);
  }

  // Nem a medida sozinha coube em tamanho legível: a peça é pequena demais, e
  // um rótulo ilegível atrapalharia a comparação de tamanho que o desenho faz.
  if (escolhidas.length === 0) return null;

  /*
   * A ordem de LEITURA é outra: o nome vem em cima, como o usuário pediu.
   * `candidatas` está em ordem de importância, que serve ao descarte; aqui o
   * nome sobe para a primeira linha e a medida desce.
   */
  const linhas = [...escolhidas].sort((a, b) => {
    const peso = (l: (typeof escolhidas)[number]) =>
      l.classe.includes("font-sans") ? 0 : l.texto.startsWith("×") ? 2 : 1;

    return peso(a) - peso(b);
  });

  // Com o nome acima, a medida recua para segundo plano.
  const temNome = linhas.some((l) => l.classe.includes("font-sans"));

  /*
   * Baseline da primeira linha.
   *
   * O `y` recebido é o centro vertical da peça. Sobe-se metade do bloco e
   * desce-se um terço da fonte, que é o ajuste entre o centro geométrico do
   * texto e a linha de base onde o SVG o assenta.
   */
  const topo = -((linhas.length - 1) / 2) * ENTRELINHA * tamanho + tamanho * 0.34;

  return (
    <text
      x={centroX}
      y={y + part.length_mm / 2}
      textAnchor="middle"
      fill="currentColor"
      fontSize={tamanho}
    >
      {linhas.map((linha, indice) => (
        <tspan
          key={indice}
          x={centroX}
          dy={indice === 0 ? topo : tamanho * ENTRELINHA}
          className={linha.classe}
          fillOpacity={
            temNome && linha.classe === "font-mono" && !linha.texto.startsWith("×")
              ? 0.75
              : linha.opacidade
          }
        >
          {linha.texto}
        </tspan>
      ))}
    </text>
  );
}

/** Espaço entre as peças, em fração da maior medida do conjunto. */
const FOLGA = 0.04;

/**
 * Arruma as peças em prateleiras, da maior para a menor.
 *
 * Não é o algoritmo de nesting do servidor e não tenta ser: aqui não há folha,
 * não há lâmina e não há sentido de fibra. É só um arranjo estável e legível —
 * a ordenação por área decrescente existe para o desenho não pular a cada
 * tecla digitada, e não para economizar chapa.
 */
function arruma(parts: CustomPartInput[]): {
  pecas: Array<{ part: CustomPartInput; x: number; y: number }>;
  largura: number;
  altura: number;
  fonte: number;
} {
  const ordenadas = [...parts].sort(
    (a, b) => b.width_mm * b.length_mm - a.width_mm * a.length_mm,
  );

  const maiorLargura = Math.max(...ordenadas.map((p) => p.width_mm));
  const areaTotal = ordenadas.reduce((soma, p) => soma + p.width_mm * p.length_mm, 0);

  // Alvo quadrado: a raiz da área total dá uma caixa envolvente próxima de 1:1,
  // que é a que melhor aproveita um painel de proporção livre.
  const limite = Math.max(maiorLargura, Math.ceil(Math.sqrt(areaTotal) * 1.2));
  const folga = limite * FOLGA;

  const pecas: Array<{ part: CustomPartInput; x: number; y: number }> = [];

  let x = 0;
  let y = 0;
  let alturaDaPrateleira = 0;

  for (const part of ordenadas) {
    // Quebra a prateleira quando a peça não cabe no que resta da linha.
    if (x > 0 && x + part.width_mm > limite) {
      x = 0;
      y += alturaDaPrateleira + folga;
      alturaDaPrateleira = 0;
    }

    pecas.push({ part, x, y });

    x += part.width_mm + folga;
    alturaDaPrateleira = Math.max(alturaDaPrateleira, part.length_mm);
  }

  return {
    pecas,
    largura: limite,
    altura: y + alturaDaPrateleira,
    fonte: limite / 32,
  };
}

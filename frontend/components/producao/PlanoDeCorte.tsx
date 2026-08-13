"use client";

import { TriangleAlert } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import type { MaterialCuttingPlan, SheetLayout } from "@/lib/api";
import { ROTULO_FIBRA } from "@/lib/rotulos";

/**
 * O plano de corte, desenhado.
 *
 * As coordenadas vêm prontas do servidor (`x`, `y`, `width_mm`, `length_mm`),
 * já com a lâmina descontada a cada corte — o SVG só as transporta para a tela
 * usando a folha como `viewBox`. Nenhum cálculo de encaixe acontece aqui: o
 * arranjo é do `NestingCalculator`, e refazê-lo no navegador criaria uma
 * segunda heurística para divergir da primeira.
 *
 * INFORMATIVO. O preço continua saindo do percentual de perda cadastrado; este
 * plano existe para mostrar o quanto esse percentual erra.
 */
export function PlanoDeCorte({ plano }: { plano: MaterialCuttingPlan }) {
  const perdeMais = plano.divergence_percent > 0;

  return (
    <Card>
      <CardContent className="space-y-4 p-4">
        <header className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 className="text-sm font-semibold">{plano.material.name}</h3>
            <p className="text-xs text-muted-foreground">
              Folha {plano.sheet.width_mm} × {plano.sheet.length_mm} mm · lâmina de{" "}
              {plano.kerf_mm} mm ·{" "}
              {ROTULO_FIBRA[plano.grain_direction] ?? plano.grain_direction}
            </p>
          </div>

          <Badge variant="outline" className="font-mono text-[10px]">
            {plano.truncated
              ? `~${plano.sheets_estimated} folhas`
              : `${plano.sheets_needed} folha${plano.sheets_needed === 1 ? "" : "s"}`}
          </Badge>
        </header>

        {/* ── Orçado × real ───────────────────────────────────────────────
            A comparação é o produto deste módulo. Um plano de corte que só
            desenhasse retângulos seria bonito e inútil: o que muda uma decisão
            é saber que a perda cobrada não cobre a perda que acontece. */}
        <div className="grid grid-cols-3 gap-3 rounded-lg border p-3">
          <Indicador rotulo="Perda orçada" valor={`${plano.quoted_waste_percent}%`} />
          <Indicador rotulo="Perda real" valor={`${plano.real_waste_percent}%`} />
          <Indicador
            rotulo="Diferença"
            valor={`${plano.divergence_percent > 0 ? "+" : ""}${plano.divergence_percent}%`}
            tom={perdeMais ? "ruim" : "bom"}
          />
        </div>

        {perdeMais && (
          <p className="rounded-md bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-500">
            O corte desperdiça{" "}
            <strong className="font-medium">{plano.divergence_percent} pontos</strong> a
            mais do que o orçamento cobra. A diferença sai do lucro sem aparecer em
            lugar nenhum.
          </p>
        )}

        {plano.truncated && (
          <p className="flex items-start gap-2 rounded-md bg-muted/60 px-3 py-2 text-xs text-muted-foreground">
            <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
            Pedido grande demais para desenhar inteiro: o arranjo foi calculado
            sobre {plano.pieces_planned.toLocaleString("pt-BR")} das{" "}
            {plano.pieces_total.toLocaleString("pt-BR")} peças, e o total de folhas
            é uma extrapolação.
          </p>
        )}

        {/* Primeiras folhas apenas: num pedido de 40 folhas, as 38 do meio são
            idênticas. A ÚLTIMA vai junto porque costuma ser a pior — é ela que
            diz se compensa juntar dois pedidos numa tiragem só. */}
        <div className="grid gap-3 sm:grid-cols-2">
          {folhasParaDesenhar(plano.layouts).map((folha) => (
            <FolhaSvg key={folha.sheet_id} folha={folha} />
          ))}
        </div>

        {plano.layouts.length > 3 && (
          <p className="text-center text-[11px] text-muted-foreground">
            Mostrando 3 de {plano.layouts.length} folhas — as intermediárias repetem
            o mesmo arranjo.
          </p>
        )}
      </CardContent>
    </Card>
  );
}

/** Duas primeiras e a última: o padrão e o resto. */
function folhasParaDesenhar(layouts: SheetLayout[]): SheetLayout[] {
  if (layouts.length <= 3) return layouts;

  return [layouts[0], layouts[1], layouts[layouts.length - 1]];
}

function Indicador({
  rotulo,
  valor,
  tom,
}: {
  rotulo: string;
  valor: string;
  tom?: "bom" | "ruim";
}) {
  const cor =
    tom === "ruim"
      ? "text-destructive"
      : tom === "bom"
        ? "text-emerald-600 dark:text-emerald-500"
        : "";

  return (
    <div>
      <p className="text-[11px] text-muted-foreground">{rotulo}</p>
      <p className={`font-mono text-lg font-semibold tabular-nums ${cor}`}>{valor}</p>
    </div>
  );
}

/**
 * Uma folha, em escala.
 *
 * `viewBox` nas medidas reais em mm: as coordenadas do servidor entram sem
 * conversão nenhuma, e o navegador cuida da escala. Converter aqui seria
 * introduzir um fator que pode divergir do arranjo calculado.
 */
function FolhaSvg({ folha }: { folha: SheetLayout }) {
  const fonte = Math.max(folha.width_mm, folha.length_mm) / 45;

  return (
    <figure className="space-y-1">
      <svg
        viewBox={`0 0 ${folha.width_mm} ${folha.length_mm}`}
        preserveAspectRatio="xMidYMid meet"
        className="w-full rounded-md border bg-muted/20"
        role="img"
        aria-label={`Folha ${folha.sheet_id} com ${folha.parts.length} peças, ${folha.efficiency_percent}% de aproveitamento`}
      >
        {folha.parts.map((peca, indice) => (
          <g key={`${peca.name}-${indice}`}>
            <rect
              x={peca.x}
              y={peca.y}
              width={peca.width_mm}
              height={peca.length_mm}
              className="fill-primary/25 stroke-primary/60"
              strokeWidth={folha.width_mm / 400}
            />

            {/* O rótulo só cabe em peça grande: numa peça pequena ele sairia por
                cima das vizinhas e esconderia justamente o arranjo. */}
            {peca.width_mm > fonte * 5 && peca.length_mm > fonte * 2 && (
              <text
                x={peca.x + peca.width_mm / 2}
                y={peca.y + peca.length_mm / 2}
                textAnchor="middle"
                dominantBaseline="middle"
                fontSize={fonte}
                className="fill-foreground/70 font-mono"
              >
                {peca.rotated ? "↻ " : ""}
                {Math.round(peca.width_mm)}×{Math.round(peca.length_mm)}
              </text>
            )}
          </g>
        ))}
      </svg>

      <figcaption className="flex items-center justify-between text-[11px] text-muted-foreground">
        <span>Folha {folha.sheet_id}</span>
        <span className="font-mono tabular-nums">
          {folha.efficiency_percent}% aproveitada
        </span>
      </figcaption>
    </figure>
  );
}

"use client";

import { useState } from "react";
import Link from "next/link";
import { ChevronLeft, ChevronRight, TriangleAlert } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

import { PageHeader } from "@/components/PageHeader";
import { ErrorState } from "@/components/data/states";
import { useApi } from "@/hooks/useApi";
import { api } from "@/lib/api";
import { formatCurrency } from "@/lib/pricing/engine";

const MESES = [
  "janeiro", "fevereiro", "março", "abril", "maio", "junho",
  "julho", "agosto", "setembro", "outubro", "novembro", "dezembro",
];

/**
 * Painel financeiro do mês.
 *
 * Separa REALIZADO de PROJETADO em toda linha, e a distinção é o ponto: o
 * realizado é o dinheiro que passou pelo caixa (parcela quitada), o projetado é
 * o que está prometido (parcela em aberto). Confundir os dois é como uma
 * empresa lucrativa quebra — vendeu bem, e não recebeu.
 *
 * Sem biblioteca de gráfico: as barras são divs com largura proporcional, do
 * mesmo jeito que a composição de preço da calculadora. Um pacote de 500 kB para
 * desenhar cinco retângulos seria caro pelo que entrega.
 */
export default function FinanceiroPage() {
  const hoje = new Date();
  const [mes, setMes] = useState(hoje.getMonth() + 1);
  const [ano, setAno] = useState(hoje.getFullYear());

  const painel = useApi(`financeiro:${ano}:${mes}`, () => api.finance.dashboard(mes, ano));

  function mover(passo: number) {
    const data = new Date(ano, mes - 1 + passo, 1);

    setMes(data.getMonth() + 1);
    setAno(data.getFullYear());
  }

  if (painel.error) return <ErrorState message={painel.error} onRetry={painel.refetch} />;

  const d = painel.data;

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <PageHeader
        title="Painel financeiro"
        description="O que entrou de verdade, o que está prometido, e o quanto falta para o mês se pagar."
        actions={
          <div className="flex items-center gap-1">
            <Button variant="outline" size="icon-sm" aria-label="Mês anterior" onClick={() => mover(-1)}>
              <ChevronLeft />
            </Button>
            <span className="min-w-36 text-center text-sm font-medium">
              {MESES[mes - 1]} de {ano}
            </span>
            <Button variant="outline" size="icon-sm" aria-label="Próximo mês" onClick={() => mover(1)}>
              <ChevronRight />
            </Button>
          </div>
        }
      />

      {!d ? (
        <Skeleton className="h-96 w-full" />
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-3">
            <Numero
              rotulo="Entrou"
              valor={d.revenue.realized}
              detalhe={`${formatCurrency(d.revenue.projected)} ainda a receber`}
            />
            <Numero
              rotulo="Saiu"
              valor={d.expenses.realized}
              detalhe={`${formatCurrency(d.expenses.projected)} ainda a pagar`}
              // Vermelho só quando saiu dinheiro de verdade: zero em vermelho
              // lê como problema, e não ter despesa no mês não é um.
              negativo={d.expenses.realized > 0}
            />
            <Numero
              rotulo="Resultado"
              valor={d.net_realized}
              detalhe="entradas menos saídas, no caixa"
              negativo={d.net_realized < 0}
              destaque
            />
          </div>

          {/* ── Vencidas ─────────────────────────────────────────────────── */}
          {d.overdue.count > 0 && (
            <Card className="border-destructive/40 bg-destructive/5">
              <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
                <div className="flex items-start gap-2">
                  <TriangleAlert className="mt-0.5 size-4 shrink-0 text-destructive" />
                  <div>
                    <p className="text-sm font-medium">
                      {d.overdue.count} parcela{d.overdue.count === 1 ? "" : "s"} vencida
                      {d.overdue.count === 1 ? "" : "s"}
                    </p>
                    <p className="font-mono text-xs tabular-nums text-muted-foreground">
                      {formatCurrency(d.overdue.amount)} em atraso
                    </p>
                  </div>
                </div>

                <Button asChild size="sm" variant="outline">
                  <Link href="/financeiro/parcelas?overdue=1">Ver parcelas</Link>
                </Button>
              </CardContent>
            </Card>
          )}

          {/* ── Ponto de equilíbrio ──────────────────────────────────────── */}
          <PontoDeEquilibrio
            equilibrio={d.break_even}
            realizado={d.revenue.realized}
          />

          {/* ── De onde veio o dinheiro ──────────────────────────────────── */}
          <Card>
            <CardContent className="space-y-3 p-4">
              <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                De onde veio a receita
              </h2>

              {d.revenue_distribution.length === 0 ||
              d.revenue_distribution.every((f) => f.amount === 0) ? (
                <p className="py-2 text-xs text-muted-foreground">
                  Nada recebido neste mês ainda.
                </p>
              ) : (
                <ul className="space-y-2">
                  {d.revenue_distribution
                    .filter((fatia) => fatia.amount > 0)
                    .map((fatia) => (
                      <li key={fatia.label} className="space-y-1">
                        <div className="flex items-baseline justify-between text-xs">
                          <span>{fatia.label}</span>
                          <span className="font-mono tabular-nums">
                            {formatCurrency(fatia.amount)} · {fatia.percent}%
                          </span>
                        </div>
                        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                          <div
                            className="h-full bg-primary"
                            style={{ width: `${Math.min(fatia.percent, 100)}%` }}
                          />
                        </div>
                      </li>
                    ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}

function Numero({
  rotulo,
  valor,
  detalhe,
  negativo,
  destaque,
}: {
  rotulo: string;
  valor: number;
  detalhe?: string;
  negativo?: boolean;
  destaque?: boolean;
}) {
  return (
    <Card className={destaque ? "border-primary/20 bg-primary/5" : ""}>
      <CardContent className="space-y-0.5 p-4">
        <p className="text-xs text-muted-foreground">{rotulo}</p>
        <p
          className={`font-mono text-xl font-semibold tabular-nums ${
            negativo ? "text-destructive" : ""
          }`}
        >
          {formatCurrency(valor)}
        </p>
        {detalhe && <p className="text-[11px] text-muted-foreground">{detalhe}</p>}
      </CardContent>
    </Card>
  );
}

/**
 * Ponto de equilíbrio.
 *
 * Quanto a empresa precisa faturar para cobrir o custo fixo, dada a margem de
 * contribuição que as vendas do mês vêm entregando. O `basis` do servidor diz
 * quando a conta NÃO pôde ser feita — sem vendas não há margem a observar —, e
 * mostrar isso é melhor que exibir uma meta inventada.
 */
function PontoDeEquilibrio({
  equilibrio,
  realizado,
}: {
  equilibrio: Record<string, number | string | null>;
  realizado: number;
}) {
  const meta = Number(equilibrio.target_revenue ?? 0);
  const margem = equilibrio.contribution_margin_percent;
  const semBase = equilibrio.basis !== undefined && equilibrio.basis !== null;

  const proporcao = meta > 0 ? Math.min(realizado / meta, 1) : 0;
  const atingiu = meta > 0 && realizado >= meta;

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Ponto de equilíbrio
          </h2>
          <span className="font-mono text-sm tabular-nums">
            {formatCurrency(realizado)} de {formatCurrency(meta)}
          </span>
        </div>

        <div className="h-2 overflow-hidden rounded-full bg-muted">
          <div
            className={`h-full ${atingiu ? "bg-emerald-500" : "bg-amber-500"}`}
            style={{ width: `${proporcao * 100}%` }}
          />
        </div>

        <p className="text-xs text-muted-foreground">
          {atingiu
            ? "O mês já cobriu o custo fixo. Daqui para frente, a margem vira lucro."
            : `Faltam ${formatCurrency(Math.max(meta - realizado, 0))} de faturamento para cobrir o custo fixo.`}
          {margem !== null && margem !== undefined && (
            <>
              {" "}
              Margem de contribuição observada:{" "}
              <span className="font-mono tabular-nums">{String(margem)}%</span>.
            </>
          )}
          {semBase && equilibrio.basis === "sem-vendas" && (
            <> Sem vendas no mês, a meta mostrada é o próprio custo fixo.</>
          )}
        </p>
      </CardContent>
    </Card>
  );
}

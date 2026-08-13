"use client";

import Link from "next/link";
import {
  ArrowRight,
  CalendarClock,
  FileText,
  Plus,
  TrendingUp,
} from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { ErrorState } from "@/components/data/states";

import { useApi } from "@/hooks/useApi";
import { api, type Installment, type QuoteListItem } from "@/lib/api";
import { useAccount } from "@/store/useAccount";
import { formatCurrency } from "@/lib/pricing/engine";
import { ROTULO_STATUS, TOM_STATUS } from "@/lib/rotulos";

/**
 * Painel inicial — o dia da empresa em uma tela.
 *
 * Três perguntas, nesta ordem: quanto entrou, o que está para vencer, e o que
 * foi orçado por último. É a ordem em que quem toca uma cartonagem abre o
 * sistema de manhã — e a razão de o painel não começar com um gráfico bonito de
 * doze meses, que é bom para apresentar e ruim para trabalhar.
 *
 * Tudo aqui vem de endpoints que já existiam: é montagem, não backend novo.
 */
export default function PainelPage() {
  const conta = useAccount((s) => s.account);
  const hoje = new Date();

  const financeiro = useApi(`painel:financeiro:${hoje.getMonth()}`, () =>
    api.finance.dashboard(hoje.getMonth() + 1, hoje.getFullYear()),
  );

  const orcamentos = useApi("painel:orcamentos", () =>
    api.quotes.list({ per_page: 5 }),
  );

  const vencendo = useApi("painel:parcelas", () =>
    api.finance.installments.list({ status: "pending", per_page: 5 }),
  );

  const primeiroNome = conta?.user.name.split(" ")[0] ?? "";

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">
            {primeiroNome ? `Olá, ${primeiroNome}` : "Painel"}
          </h1>
          <p className="text-sm text-muted-foreground">
            O resumo de {hoje.toLocaleDateString("pt-BR", { month: "long", year: "numeric" })}
          </p>
        </div>

        <Button asChild>
          <Link href="/calculadora">
            <Plus className="size-4" />
            Novo orçamento
          </Link>
        </Button>
      </header>

      {/* ── Os três números ──────────────────────────────────────────────── */}
      <div className="grid gap-3 sm:grid-cols-3">
        <NumeroCard
          rotulo="Recebido no mês"
          valor={financeiro.data ? formatCurrency(financeiro.data.revenue.realized) : null}
          detalhe={
            financeiro.data
              ? `${formatCurrency(financeiro.data.revenue.projected)} previsto`
              : undefined
          }
          icone={<TrendingUp className="size-4" />}
          href="/financeiro"
        />

        <NumeroCard
          rotulo="Resultado do mês"
          valor={financeiro.data ? formatCurrency(financeiro.data.net_realized) : null}
          detalhe={
            financeiro.data
              ? `${formatCurrency(financeiro.data.expenses.realized)} de despesa`
              : undefined
          }
          negativo={(financeiro.data?.net_realized ?? 0) < 0}
          icone={<TrendingUp className="size-4" />}
          href="/financeiro"
        />

        <NumeroCard
          rotulo="Parcelas vencidas"
          valor={
            financeiro.data
              ? `${financeiro.data.overdue.count} · ${formatCurrency(financeiro.data.overdue.amount)}`
              : null
          }
          // Vencido é o número que exige ação hoje: destacado quando existe,
          // discreto quando é zero.
          negativo={(financeiro.data?.overdue.count ?? 0) > 0}
          icone={<CalendarClock className="size-4" />}
          href="/financeiro/parcelas"
        />
      </div>

      {financeiro.error && (
        <ErrorState message={financeiro.error} onRetry={financeiro.refetch} />
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        {/* ── Últimos orçamentos ─────────────────────────────────────────── */}
        <Card>
          <CardContent className="space-y-3 p-4">
            <CabecalhoDeBloco
              titulo="Últimos orçamentos"
              icone={<FileText className="size-4" />}
              href="/orcamentos"
            />

            {orcamentos.loading && <Skeleton className="h-24 w-full" />}

            {!orcamentos.loading && (orcamentos.data?.items.length ?? 0) === 0 && (
              <p className="py-4 text-center text-xs text-muted-foreground">
                Nenhum orçamento ainda. O primeiro sai da calculadora.
              </p>
            )}

            <ul className="divide-y">
              {orcamentos.data?.items.map((orcamento: QuoteListItem) => (
                <li key={orcamento.id}>
                  <Link
                    href={`/orcamentos/${orcamento.id}`}
                    className="flex items-center gap-3 py-2 text-sm hover:bg-muted/40"
                  >
                    <span className="font-mono text-xs text-muted-foreground">
                      {orcamento.reference}
                    </span>
                    <span className="min-w-0 flex-1 truncate">
                      {orcamento.client.name}
                    </span>
                    <Badge variant={TOM_STATUS[orcamento.status]} className="text-[10px]">
                      {ROTULO_STATUS[orcamento.status]}
                    </Badge>
                    <span className="font-mono text-xs tabular-nums">
                      {formatCurrency(orcamento.pricing.total_price)}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>

        {/* ── A vencer ───────────────────────────────────────────────────── */}
        <Card>
          <CardContent className="space-y-3 p-4">
            <CabecalhoDeBloco
              titulo="Próximas parcelas"
              icone={<CalendarClock className="size-4" />}
              href="/financeiro/parcelas"
            />

            {vencendo.loading && <Skeleton className="h-24 w-full" />}

            {!vencendo.loading && (vencendo.data?.items.length ?? 0) === 0 && (
              <p className="py-4 text-center text-xs text-muted-foreground">
                Nada em aberto. Todas as parcelas estão quitadas.
              </p>
            )}

            <ul className="divide-y">
              {vencendo.data?.items.map((parcela: Installment) => (
                <li key={parcela.id} className="flex items-center gap-3 py-2 text-sm">
                  <span className="font-mono text-xs text-muted-foreground">
                    {formatarData(parcela.due_date)}
                  </span>
                  <span className="min-w-0 flex-1 truncate">
                    {parcela.transaction?.description ?? "Lançamento"}
                  </span>
                  <span
                    className={`font-mono text-xs tabular-nums ${
                      parcela.transaction?.type === "exit" ? "text-destructive" : ""
                    }`}
                  >
                    {formatCurrency(parcela.amount)}
                  </span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      </div>

      {/* ── Consumo do plano ─────────────────────────────────────────────── */}
      {conta?.cotas && <ConsumoDoPlano cotas={conta.cotas} plano={conta.plano?.rotulo} />}
    </div>
  );
}

function CabecalhoDeBloco({
  titulo,
  icone,
  href,
}: {
  titulo: string;
  icone: React.ReactNode;
  href: string;
}) {
  return (
    <div className="flex items-center justify-between">
      <h2 className="flex items-center gap-2 text-sm font-semibold">
        <span className="text-muted-foreground">{icone}</span>
        {titulo}
      </h2>

      <Button asChild variant="ghost" size="xs" className="text-muted-foreground">
        <Link href={href}>
          ver tudo
          <ArrowRight className="size-3" />
        </Link>
      </Button>
    </div>
  );
}

function NumeroCard({
  rotulo,
  valor,
  detalhe,
  icone,
  href,
  negativo,
}: {
  rotulo: string;
  valor: string | null;
  detalhe?: string;
  icone: React.ReactNode;
  href: string;
  negativo?: boolean;
}) {
  return (
    <Card>
      <CardContent className="space-y-1 p-4">
        <div className="flex items-center justify-between text-xs text-muted-foreground">
          <span>{rotulo}</span>
          {icone}
        </div>

        {valor === null ? (
          <Skeleton className="h-7 w-28" />
        ) : (
          <Link
            href={href}
            className={`block font-mono text-xl font-semibold tabular-nums ${
              negativo ? "text-destructive" : ""
            }`}
          >
            {valor}
          </Link>
        )}

        {detalhe && <p className="text-[11px] text-muted-foreground">{detalhe}</p>}
      </CardContent>
    </Card>
  );
}

/**
 * Consumo das cotas.
 *
 * Aparece no painel, e não escondido na tela de assinatura, porque bater no
 * limite no meio de um orçamento é a pior hora de descobrir que ele existe.
 */
function ConsumoDoPlano({
  cotas,
  plano,
}: {
  cotas: Record<string, { usado: number; limite: number | null; rotulo: string }>;
  plano?: string;
}) {
  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold">Plano {plano ?? ""}</h2>
          <Button asChild variant="ghost" size="xs" className="text-muted-foreground">
            <Link href="/assinatura">
              gerenciar
              <ArrowRight className="size-3" />
            </Link>
          </Button>
        </div>

        <div className="grid gap-3 sm:grid-cols-3">
          {Object.entries(cotas).map(([chave, cota]) => {
            const ilimitado = cota.limite === null;
            const proporcao = ilimitado ? 0 : Math.min(cota.usado / (cota.limite || 1), 1);
            const apertado = proporcao >= 0.8;

            return (
              <div key={chave} className="space-y-1">
                <div className="flex items-baseline justify-between text-xs">
                  <span className="text-muted-foreground">{cota.rotulo}</span>
                  <span className="font-mono tabular-nums">
                    {cota.usado}
                    {ilimitado ? "" : ` / ${cota.limite}`}
                  </span>
                </div>

                {/*
                  Sem limite, sem barra. Uma barra vazia num plano ilimitado lê
                  como "0% usado de alguma coisa" — sugere um teto que não
                  existe, e é a leitura oposta da verdadeira.
                */}
                {ilimitado ? (
                  <p className="text-[11px] text-muted-foreground">sem limite</p>
                ) : (
                  <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                      className={`h-full ${apertado ? "bg-amber-500" : "bg-primary"}`}
                      style={{ width: `${proporcao * 100}%` }}
                    />
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </CardContent>
    </Card>
  );
}

function formatarData(iso: string): string {
  // `split` em vez de `new Date`: a data vem como "2026-08-10" e o construtor a
  // interpreta em UTC, o que exibe o dia anterior em qualquer fuso a oeste.
  const [ano, mes, dia] = iso.slice(0, 10).split("-");

  return `${dia}/${mes}/${ano.slice(2)}`;
}

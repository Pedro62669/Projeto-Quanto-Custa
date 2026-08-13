"use client";

import { Suspense, useState } from "react";
import { useSearchParams } from "next/navigation";
import { Check, Undo2 } from "lucide-react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

import { PageHeader } from "@/components/PageHeader";
import {
  DataTable,
  EmptyState,
  Pagination,
  type Column,
} from "@/components/data/DataTable";

import { useApi, mensagemDeErro } from "@/hooks/useApi";
import { api, type Installment } from "@/lib/api";
import { formatCurrency } from "@/lib/pricing/engine";
import { formatarData } from "@/lib/rotulos";

const TODAS = "todas";

export default function ParcelasPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full" />}>
      <Conteudo />
    </Suspense>
  );
}

/**
 * Contas a receber e a pagar.
 *
 * A tela onde o dinheiro prometido vira dinheiro recebido. Dar baixa é o que
 * move a parcela de "projetado" para "realizado" no painel — e é por isso que
 * o estorno existe: a baixa errada não pode ser corrigida apagando o registro,
 * porque a parcela precisa continuar existindo.
 */
function Conteudo() {
  const parametros = useSearchParams();

  const [pagina, setPagina] = useState(1);
  const [status, setStatus] = useState(TODAS);
  const [somenteVencidas, setSomenteVencidas] = useState(
    parametros.get("overdue") === "1",
  );

  const lista = useApi(`parcelas:${pagina}:${status}:${somenteVencidas}`, () =>
    api.finance.installments.list({
      page: pagina,
      status: status === TODAS ? undefined : status,
      overdue: somenteVencidas || undefined,
    }),
  );

  const hoje = new Date().toISOString().slice(0, 10);

  const colunas: Column<Installment>[] = [
    {
      header: "Vencimento",
      render: (p) => {
        const vencida = p.status === "pending" && p.due_date.slice(0, 10) < hoje;

        return (
          <div>
            <p className={`font-mono text-xs tabular-nums ${vencida ? "text-destructive" : ""}`}>
              {formatarData(p.due_date)}
            </p>
            {vencida && <p className="text-[10px] text-destructive">em atraso</p>}
          </div>
        );
      },
    },
    {
      header: "Lançamento",
      render: (p) => (
        <div className="min-w-0">
          <p className="truncate">{p.transaction?.description ?? "—"}</p>
          <p className="text-xs text-muted-foreground">
            parcela {p.number} de {p.total}
          </p>
        </div>
      ),
    },
    {
      header: "Situação",
      render: (p) =>
        p.status === "completed" ? (
          <Badge variant="secondary" className="text-[10px]">
            quitada {p.payment_date ? `em ${formatarData(p.payment_date)}` : ""}
          </Badge>
        ) : (
          <Badge variant="outline" className="text-[10px]">
            em aberto
          </Badge>
        ),
    },
    {
      header: "Valor",
      className: "text-right",
      render: (p) => (
        <span
          className={`font-mono font-medium tabular-nums ${
            p.transaction?.type === "exit" ? "text-destructive" : ""
          }`}
        >
          {p.transaction?.type === "exit" ? "−" : ""}
          {formatCurrency(p.amount)}
        </span>
      ),
    },
    {
      header: "",
      className: "w-28 text-right",
      render: (p) =>
        p.status === "completed" ? (
          <Button
            variant="ghost"
            size="sm"
            className="text-muted-foreground"
            onClick={async () => {
              try {
                await api.finance.unsettle(p.id);
                toast.success("Baixa estornada");
                lista.refetch();
              } catch (erro) {
                toast.error(mensagemDeErro(erro));
              }
            }}
          >
            <Undo2 className="size-3.5" />
            Estornar
          </Button>
        ) : (
          <Button
            variant="outline"
            size="sm"
            onClick={async () => {
              try {
                await api.finance.settle(p.id);
                toast.success("Parcela quitada");
                lista.refetch();
              } catch (erro) {
                toast.error(mensagemDeErro(erro));
              }
            }}
          >
            <Check className="size-3.5" />
            Dar baixa
          </Button>
        ),
    },
  ];

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <PageHeader
        title="Parcelas"
        description="O que está para entrar e para sair. Dar baixa é o que move o valor de previsto para realizado."
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          value={status}
          onValueChange={(v) => {
            setStatus(v);
            setPagina(1);
          }}
        >
          <SelectTrigger className="w-44" aria-label="Filtrar por situação">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={TODAS}>Todas</SelectItem>
            <SelectItem value="pending">Em aberto</SelectItem>
            <SelectItem value="completed">Quitadas</SelectItem>
          </SelectContent>
        </Select>

        <Button
          variant={somenteVencidas ? "default" : "outline"}
          size="sm"
          onClick={() => {
            setSomenteVencidas((v) => !v);
            setPagina(1);
          }}
        >
          Só as vencidas
        </Button>
      </div>

      <DataTable
        columns={colunas}
        rows={lista.data?.items ?? []}
        loading={lista.loading}
        error={lista.error}
        onRetry={lista.refetch}
        rowKey={(p) => p.id}
        empty={
          <EmptyState
            title="Nenhuma parcela"
            description="As parcelas nascem dos lançamentos e dos orçamentos aprovados."
          />
        }
      />

      <Pagination page={lista.data} onChange={setPagina} />
    </div>
  );
}

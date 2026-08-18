"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { Plus } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
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

import { useApi } from "@/hooks/useApi";
import { api, type QuoteListItem } from "@/lib/api";
import { formatCurrency } from "@/lib/pricing/engine";
import { ROTULO_MODELO, ROTULO_STATUS, TOM_STATUS, formatarData } from "@/lib/rotulos";
import { useDebounce } from "@/hooks/useDebounce";

const TODOS = "todos";

/**
 * Histórico de orçamentos.
 *
 * A tela que faltava para o trabalho não sumir: até aqui a calculadora gravava
 * a proposta e nada no sistema a mostrava de volta. Quem precisasse do número
 * de ontem tinha que refazer a conta.
 */
export default function OrcamentosPage() {
  const router = useRouter();

  const [pagina, setPagina] = useState(1);
  const [cliente, setCliente] = useState("");
  const [status, setStatus] = useState(TODOS);

  // Busca em debounce: sem isso, cada tecla vira uma consulta paginada no
  // servidor — e as respostas chegam fora de ordem.
  const clienteBuscado = useDebounce(cliente, 400);

  const lista = useApi(`orcamentos:${pagina}:${clienteBuscado}:${status}`, () =>
    api.quotes.list({
      page: pagina,
      client: clienteBuscado || undefined,
      status: status === TODOS ? undefined : status,
    }),
  );

  const colunas: Column<QuoteListItem>[] = [
    {
      header: "Referência",
      render: (q) => (
        <div>
          <p className="font-mono text-xs">{q.reference}</p>
          <p className="text-xs text-muted-foreground">{formatarData(q.created_at)}</p>
        </div>
      ),
    },
    {
      header: "Cliente",
      render: (q) => (
        <div className="min-w-0">
          <p className="truncate font-medium">{q.client.name}</p>
          <p className="truncate text-xs text-muted-foreground">
            {ROTULO_MODELO[q.specification.box_model] ?? q.specification.box_model} ·{" "}
            {q.specification.quantity.toLocaleString("pt-BR")} un.
          </p>
        </div>
      ),
    },
    {
      header: "Situação",
      render: (q) => (
        <Badge variant={TOM_STATUS[q.status]} className="text-[10px]">
          {ROTULO_STATUS[q.status] ?? q.status}
        </Badge>
      ),
    },
    {
      header: "Total",
      className: "text-right",
      render: (q) => (
        <div>
          <p className="font-mono font-medium tabular-nums">
            {formatCurrency(q.pricing.total_price)}
          </p>
          <p className="font-mono text-xs text-muted-foreground tabular-nums">
            {formatCurrency(q.pricing.unit_price, "BRL", 4)}/un.
          </p>
        </div>
      ),
    },
  ];

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <PageHeader
        title="Orçamentos"
        description="Tudo que já foi calculado e salvo, com o preço congelado no dia em que foi feito."
        actions={
          <Button asChild>
            <Link href="/calculadora">
              <Plus className="size-4 text-brand-on-inverted" />
              Novo orçamento
            </Link>
          </Button>
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <Input
          placeholder="Buscar por cliente…"
          value={cliente}
          onChange={(e) => {
            setCliente(e.target.value);

            // Filtro novo começa da primeira página: continuar na página 4 de
            // um filtro que agora tem 1 página mostraria uma lista vazia.
            setPagina(1);
          }}
          className="max-w-xs"
          aria-label="Buscar por cliente"
        />

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
            <SelectItem value={TODOS}>Todas as situações</SelectItem>
            {Object.entries(ROTULO_STATUS).map(([valor, rotulo]) => (
              <SelectItem key={valor} value={valor}>
                {rotulo}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <DataTable
        columns={colunas}
        rows={lista.data?.items ?? []}
        loading={lista.loading}
        error={lista.error}
        onRetry={lista.refetch}
        rowKey={(q) => q.id}
        onRowClick={(q) => router.push(`/orcamentos/${q.id}`)}
        empty={
          cliente || status !== TODOS ? (
            <EmptyState
              title="Nenhum orçamento com esses filtros"
              description="Limpe a busca ou escolha outra situação."
            />
          ) : (
            <EmptyState
              title="Nenhum orçamento salvo ainda"
              description="Os orçamentos nascem na calculadora: dimensione a caixa, confira o preço e salve."
              action={
                <Button asChild size="sm">
                  <Link href="/calculadora">Abrir a calculadora</Link>
                </Button>
              }
            />
          )
        }
      />

      <Pagination page={lista.data} onChange={setPagina} />
    </div>
  );
}

"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, FileText, TrendingUp } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

import { ErrorState } from "@/components/data/states";
import { useApi } from "@/hooks/useApi";
import { api } from "@/lib/api";
import { formatCurrency } from "@/lib/pricing/engine";
import {
  ROTULO_CATEGORIA,
  ROTULO_STATUS,
  TOM_STATUS,
  formatarData,
} from "@/lib/rotulos";

/**
 * A ficha do cliente — o que o cadastro passou a valer.
 *
 * Enquanto o orçamento guardava o cliente só como texto, esta tela não podia
 * existir: não havia como perguntar "o que a Papelaria Silva já comprou". A
 * pergunta agora tem resposta porque `quotes.client_id` é preenchido na
 * gravação e na aprovação.
 *
 * Só aparece o que foi ligado ao CADASTRO. Um orçamento avulso com o mesmo nome
 * digitado à mão continua fora daqui, e isso é honesto: o sistema não sabe que
 * são a mesma pessoa, e adivinhar por semelhança de texto juntaria clientes
 * diferentes de mesmo sobrenome.
 */
export default function ClientePage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);

  const cliente = useApi(`cliente:${id}`, () => api.clients.get(id));

  const orcamentos = useApi(`cliente:${id}:orcamentos`, () =>
    api.quotes.list({ client_id: id, per_page: 50 }),
  );

  const lancamentos = useApi(`cliente:${id}:lancamentos`, () =>
    api.finance.transactions.list({ client_id: id, per_page: 50 }),
  );

  if (cliente.error) {
    return <ErrorState message={cliente.error} onRetry={cliente.refetch} />;
  }

  if (!cliente.data) {
    return (
      <div className="mx-auto max-w-4xl space-y-4">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-48 w-full" />
      </div>
    );
  }

  const c = cliente.data;

  /*
   * Só o que ENTROU, e só o que já foi conciliado.
   *
   * Somar as saídas junto daria um número sem significado — despesa lançada
   * contra um cliente é frete ou devolução, não faturamento dele. E somar o
   * previsto junto do realizado responderia uma pergunta que ninguém fez: o
   * título diz "já comprou", e comprar é o que foi pago.
   */
  const faturado = (lancamentos.data?.items ?? [])
    .filter((t) => t.type === "entry")
    .reduce((soma, t) => soma + t.amount, 0);

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <div>
        <Button asChild variant="ghost" size="sm" className="-ml-2 text-muted-foreground">
          <Link href="/clientes">
            <ArrowLeft className="size-3.5" />
            Clientes
          </Link>
        </Button>
      </div>

      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <h1 className="truncate text-xl font-semibold">{c.name}</h1>
            {!c.is_active && <Badge variant="secondary">Inativo</Badge>}
          </div>
          <p className="text-sm text-muted-foreground">
            {[c.cpf_cnpj, c.email, c.phone].filter(Boolean).join(" · ") ||
              "sem contato cadastrado"}
          </p>
        </div>
      </header>

      <div className="grid gap-3 sm:grid-cols-2">
        <Resumo
          icone={<FileText className="size-4" />}
          rotulo="Orçamentos ligados"
          valor={String(orcamentos.data?.total ?? 0)}
        />
        <Resumo
          icone={<TrendingUp className="size-4" />}
          rotulo="Já faturado"
          valor={formatCurrency(faturado)}
        />
      </div>

      {/* ── Orçamentos ───────────────────────────────────────────────────── */}
      <section className="space-y-2">
        <h2 className="text-sm font-semibold">Orçamentos</h2>

        {orcamentos.error && (
          <ErrorState message={orcamentos.error} onRetry={orcamentos.refetch} />
        )}

        {orcamentos.data?.items.length === 0 && (
          <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
            Nenhum orçamento ligado a este cadastro ainda. Ao salvar um orçamento,
            escolha o cliente pela busca em vez de digitar o nome.
          </p>
        )}

        {orcamentos.data && orcamentos.data.items.length > 0 && (
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent">
                  <TableHead>Referência</TableHead>
                  <TableHead>Situação</TableHead>
                  <TableHead>Data</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {orcamentos.data.items.map((q) => (
                  <TableRow key={q.id}>
                    <TableCell>
                      <Link
                        href={`/orcamentos/${q.id}`}
                        className="font-mono text-sm font-medium text-brand-strong hover:underline"
                      >
                        {q.reference}
                      </Link>
                    </TableCell>
                    <TableCell>
                      <Badge variant={TOM_STATUS[q.status]} className="text-[10px]">
                        {ROTULO_STATUS[q.status]}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                      {formatarData(q.created_at)}
                    </TableCell>
                    <TableCell className="text-right font-mono tabular-nums">
                      {formatCurrency(q.pricing.total_price)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </section>

      {/* ── Movimento no caixa ───────────────────────────────────────────── */}
      <section className="space-y-2">
        <h2 className="text-sm font-semibold">Movimento no caixa</h2>

        {lancamentos.data?.items.length === 0 && (
          <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
            Nada lançado. Aprovar um orçamento deste cliente cria a venda aqui.
          </p>
        )}

        {lancamentos.data && lancamentos.data.items.length > 0 && (
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent">
                  <TableHead>Descrição</TableHead>
                  <TableHead>Categoria</TableHead>
                  <TableHead>Data</TableHead>
                  <TableHead className="text-right">Valor</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {lancamentos.data.items.map((t) => (
                  <TableRow key={t.id}>
                    <TableCell className="max-w-xs truncate text-sm">
                      {t.description}
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                      {ROTULO_CATEGORIA[t.category] ?? t.category}
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                      {formatarData(t.transaction_date)}
                    </TableCell>
                    <TableCell
                      className={`text-right font-mono tabular-nums ${
                        t.type === "entry" ? "text-emerald-600" : "text-destructive"
                      }`}
                    >
                      {t.type === "entry" ? "+" : "−"}
                      {formatCurrency(t.amount)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </section>
    </div>
  );
}

function Resumo({
  icone,
  rotulo,
  valor,
}: {
  icone: React.ReactNode;
  rotulo: string;
  valor: string;
}) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <div className="text-muted-foreground">{icone}</div>
        <div>
          <p className="text-xs text-muted-foreground">{rotulo}</p>
          <p className="text-lg font-semibold tabular-nums">{valor}</p>
        </div>
      </CardContent>
    </Card>
  );
}

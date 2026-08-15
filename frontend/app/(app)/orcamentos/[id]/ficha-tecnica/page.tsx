"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, Printer, TriangleAlert } from "lucide-react";

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
import { PlanoDeCorte } from "@/components/producao/PlanoDeCorte";
import { useApi } from "@/hooks/useApi";
import { api, type CutPiece } from "@/lib/api";
import { formatarData } from "@/lib/rotulos";

/**
 * Ficha técnica de produção — o documento que vai para a bancada.
 *
 * Desenhada para o PAPEL: fundo branco, sem barra lateral na impressão, e o
 * plano de corte grande o bastante para conferir peça a peça. Quem corta não
 * fica com o navegador aberto ao lado da guilhotina.
 *
 * O gabarito é calculado no SERVIDOR e chega pronto. Recalculá-lo aqui criaria
 * uma terceira implementação da geometria (PHP do preço, TS do preview, TS da
 * ficha) — e a paridade só vigia as duas primeiras.
 */
export default function FichaTecnicaPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);

  const ficha = useApi(`ficha:${id}`, () => api.quotes.technicalSheet(id));

  if (ficha.error) return <ErrorState message={ficha.error} onRetry={ficha.refetch} />;

  if (!ficha.data) {
    return (
      <div className="mx-auto max-w-4xl space-y-4">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-96 w-full" />
      </div>
    );
  }

  const f = ficha.data;

  return (
    <div className="mx-auto max-w-4xl space-y-5 print:max-w-none">
      {/* A navegação some no papel: numa folha impressa, um botão "voltar" é
          tinta gasta. */}
      <div className="flex items-center justify-between print:hidden">
        <Button asChild variant="ghost" size="sm" className="-ml-2 text-muted-foreground">
          <Link href={`/orcamentos/${id}`}>
            <ArrowLeft className="size-3.5" />
            Voltar ao orçamento
          </Link>
        </Button>

        <Button variant="outline" size="sm" onClick={() => window.print()}>
          <Printer className="size-3.5" />
          Imprimir
        </Button>
      </div>

      <header className="space-y-1 border-b pb-4">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h1 className="text-xl font-semibold">Ficha técnica de produção</h1>
          <span className="font-mono text-sm">{f.quote.reference}</span>
        </div>
        <p className="text-sm text-muted-foreground">
          {f.quote.client_name} · {formatarData(f.quote.created_at)} ·{" "}
          {f.specification.box_model_label}
        </p>
      </header>

      {/* ── A caixa ──────────────────────────────────────────────────────── */}
      <section className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
        <Dado rotulo="Medidas internas">
          {f.specification.width_mm} × {f.specification.height_mm} ×{" "}
          {f.specification.depth_mm} mm
        </Dado>
        <Dado rotulo="Espessura">{f.specification.thickness_mm} mm</Dado>
        <Dado rotulo="Material">{f.specification.material ?? "—"}</Dado>
        <Dado rotulo="Quantidade">
          {f.specification.quantity.toLocaleString("pt-BR")} un.
        </Dado>
      </section>

      {/* ── O gabarito ───────────────────────────────────────────────────── */}
      <section className="space-y-3">
        <h2 className="text-sm font-semibold">Gabarito de corte</h2>

        <TabelaDePecas titulo="Estrutura" pecas={f.cut_template.structure} />
        {f.cut_template.wrap.length > 0 && (
          <TabelaDePecas titulo="Revestimento" pecas={f.cut_template.wrap} />
        )}

        {Array.isArray(f.cut_template.notes) && f.cut_template.notes.length > 0 && (
          <ul className="space-y-1 rounded-md bg-muted/50 p-3 text-xs text-muted-foreground">
            {f.cut_template.notes.map((nota) => (
              <li key={nota}>· {nota}</li>
            ))}
          </ul>
        )}
      </section>

      {/* ── A separação ──────────────────────────────────────────────────── */}
      <section className="space-y-3">
        <h2 className="text-sm font-semibold">Lista de separação</h2>
        <p className="text-xs text-muted-foreground">
          Quantidades do <strong className="font-medium">lote inteiro</strong> — quem
          vai ao estoque não deveria multiplicar por {f.specification.quantity} na
          frente da prateleira.
        </p>

        <div className="rounded-lg border">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent">
                <TableHead>Peça</TableHead>
                <TableHead>Material</TableHead>
                <TableHead>Medida</TableHead>
                <TableHead className="text-right">Por caixa</TableHead>
                <TableHead className="text-right">Total</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {f.picking_list.map((linha, indice) => (
                <TableRow key={`${linha.piece}-${indice}`}>
                  <TableCell className="font-medium">{linha.piece}</TableCell>
                  <TableCell className="text-muted-foreground">
                    {linha.material_label}
                  </TableCell>
                  <TableCell className="font-mono text-xs">{linha.size}</TableCell>
                  <TableCell className="text-right font-mono tabular-nums">
                    {/* Fracionário desde que ferragem entrou na lista: fita de
                        cetim é comprada por peça e consumida em metro e meio.
                        Sem a formatação pt-BR a bancada leria "1.5". */}
                    {linha.per_unit.toLocaleString("pt-BR")}
                  </TableCell>
                  <TableCell className="text-right font-mono font-medium tabular-nums">
                    {linha.total.toLocaleString("pt-BR")}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </section>

      {/* ── O plano de corte ─────────────────────────────────────────────── */}
      <section className="space-y-3">
        <h2 className="text-sm font-semibold">Plano de corte</h2>

        {f.cutting_plan.warnings.map((aviso) => (
          <p
            key={aviso}
            className="flex items-start gap-2 rounded-md bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-500"
          >
            <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
            {aviso}
          </p>
        ))}

        {f.cutting_plan.by_material.map((plano) => (
          <PlanoDeCorte key={plano.material.id} plano={plano} />
        ))}

        {f.cutting_plan.by_material.length === 0 &&
          f.cutting_plan.warnings.length === 0 && (
            <Card>
              <CardContent className="p-4 text-xs text-muted-foreground">
                Sem plano de corte para este orçamento.
              </CardContent>
            </Card>
          )}

        <ul className="space-y-1 text-[11px] text-muted-foreground">
          {f.cutting_plan.notes.map((nota) => (
            <li key={nota}>· {nota}</li>
          ))}
        </ul>
      </section>

      {f.quote.notes && (
        <section className="space-y-1 border-t pt-4">
          <h2 className="text-sm font-semibold">Observações do orçamento</h2>
          <p className="text-sm whitespace-pre-wrap">{f.quote.notes}</p>
        </section>
      )}
    </div>
  );
}

function Dado({ rotulo, children }: { rotulo: string; children: React.ReactNode }) {
  return (
    <div>
      <p className="text-[11px] uppercase tracking-wider text-muted-foreground">
        {rotulo}
      </p>
      <p className="font-mono">{children}</p>
    </div>
  );
}

function TabelaDePecas({ titulo, pecas }: { titulo: string; pecas: CutPiece[] }) {
  if (pecas.length === 0) return null;

  return (
    <div className="space-y-1">
      <h3 className="text-xs font-medium text-muted-foreground">{titulo}</h3>

      <div className="rounded-lg border">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead>Peça</TableHead>
              <TableHead className="text-right">Largura</TableHead>
              <TableHead className="text-right">Altura</TableHead>
              <TableHead className="text-right">Qtd. por caixa</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {pecas.map((peca, indice) => (
              <TableRow key={`${peca.name}-${indice}`}>
                <TableCell className="font-medium">{peca.name}</TableCell>
                <TableCell className="text-right font-mono tabular-nums">
                  {peca.width_mm.toFixed(1)}
                </TableCell>
                <TableCell className="text-right font-mono tabular-nums">
                  {peca.height_mm.toFixed(1)}
                </TableCell>
                <TableCell className="text-right font-mono tabular-nums">
                  {peca.quantity}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}

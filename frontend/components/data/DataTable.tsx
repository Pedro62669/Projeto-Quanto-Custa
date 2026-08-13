"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { EmptyState, ErrorState, LoadingRows } from "@/components/data/states";
import type { Page } from "@/lib/api";

/**
 * Uma coluna da tabela.
 *
 * `render` recebe a linha inteira em vez de um valor: quase toda coluna do
 * sistema combina dois campos (nome + tipo, valor + moeda) ou decide a cor pelo
 * estado da linha.
 */
export interface Column<T> {
  header: string;
  render: (linha: T) => React.ReactNode;
  /** Classe aplicada à célula E ao cabeçalho — alinhamento à direita, largura. */
  className?: string;
}

/**
 * Tabela padrão de listagem.
 *
 * Concentra os quatro estados (carregando, erro, vazio, conteúdo) para que uma
 * tela de cadastro seja só a definição das colunas e do formulário. É a peça
 * que evita dez implementações ligeiramente diferentes da mesma listagem — e,
 * com elas, dez comportamentos diferentes quando a rede falha.
 */
export function DataTable<T>({
  columns,
  rows,
  loading,
  error,
  onRetry,
  onRowClick,
  rowKey,
  empty,
}: {
  columns: Column<T>[];
  rows: T[];
  loading: boolean;
  error: string | null;
  onRetry?: () => void;
  onRowClick?: (linha: T) => void;
  rowKey: (linha: T) => string | number;
  empty: React.ReactNode;
}) {
  if (error) return <ErrorState message={error} onRetry={onRetry} />;

  // Carregando de novo com dados na tela (troca de página, refetch depois de
  // salvar): mantém a tabela e não pisca — só o primeiro carregamento mostra o
  // esqueleto, porque só nele não há nada para mostrar.
  if (loading && rows.length === 0) return <LoadingRows />;

  if (rows.length === 0) return <>{empty}</>;

  return (
    <div className="rounded-lg border" data-loading={loading || undefined}>
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            {columns.map((coluna) => (
              <TableHead key={coluna.header} className={coluna.className}>
                {coluna.header}
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>

        <TableBody>
          {rows.map((linha) => (
            <TableRow
              key={rowKey(linha)}
              onClick={onRowClick ? () => onRowClick(linha) : undefined}
              className={onRowClick ? "cursor-pointer" : undefined}
            >
              {columns.map((coluna) => (
                <TableCell key={coluna.header} className={coluna.className}>
                  {coluna.render(linha)}
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

/**
 * Paginação.
 *
 * Mostra o intervalo e o total, não só os botões: "26–50 de 312" responde
 * "onde estou" sem o usuário precisar contar páginas.
 */
export function Pagination({
  page,
  onChange,
  className = "",
}: {
  page: Page<unknown> | null;
  onChange: (pagina: number) => void;
  className?: string;
}) {
  if (!page || page.lastPage <= 1) return null;

  const primeiro = (page.page - 1) * page.perPage + 1;
  const ultimo = Math.min(page.page * page.perPage, page.total);

  return (
    <div className={`flex items-center justify-between gap-3 ${className}`}>
      <span className="text-xs text-muted-foreground">
        <span className="font-mono tabular-nums">
          {primeiro}–{ultimo}
        </span>{" "}
        de <span className="font-mono tabular-nums">{page.total}</span>
      </span>

      <div className="flex items-center gap-1">
        <Button
          variant="outline"
          size="icon-sm"
          aria-label="Página anterior"
          disabled={page.page <= 1}
          onClick={() => onChange(page.page - 1)}
        >
          <ChevronLeft />
        </Button>

        <span className="px-2 font-mono text-xs tabular-nums">
          {page.page} / {page.lastPage}
        </span>

        <Button
          variant="outline"
          size="icon-sm"
          aria-label="Próxima página"
          disabled={page.page >= page.lastPage}
          onClick={() => onChange(page.page + 1)}
        >
          <ChevronRight />
        </Button>
      </div>
    </div>
  );
}

export { EmptyState, ErrorState };

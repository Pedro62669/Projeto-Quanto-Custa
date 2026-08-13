"use client";

import { Inbox, RefreshCw, TriangleAlert } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * Os estados que não são "deu certo".
 *
 * Existem como componentes próprios porque toda listagem do sistema precisa dos
 * três, e escrevê-los à mão em cada tela é como eles acabam faltando — a tabela
 * vazia por falha de rede é indistinguível da tabela vazia por não haver
 * cadastro, e o usuário conclui a coisa errada.
 */

export function LoadingRows({ rows = 5 }: { rows?: number }) {
  return (
    <div className="space-y-2" aria-busy aria-label="Carregando">
      {Array.from({ length: rows }, (_, i) => (
        <Skeleton key={i} className="h-10 w-full" />
      ))}
    </div>
  );
}

/**
 * Vazio COM saída.
 *
 * Um "nenhum registro encontrado" seco deixa o usuário sem próximo passo. A
 * ação primária vem junto: é a mesma do topo da tela, repetida onde o olhar já
 * está.
 */
export function EmptyState({
  title,
  description,
  action,
}: {
  title: string;
  description?: string;
  action?: React.ReactNode;
}) {
  return (
    <Card className="border-dashed">
      <CardContent className="flex flex-col items-center gap-2 p-10 text-center">
        <Inbox className="size-8 text-muted-foreground/50" />
        <p className="text-sm font-medium">{title}</p>
        {description && (
          <p className="max-w-sm text-xs text-muted-foreground">{description}</p>
        )}
        {action && <div className="pt-1">{action}</div>}
      </CardContent>
    </Card>
  );
}

/** Falha COM saída: a mensagem do servidor e um botão para tentar de novo. */
export function ErrorState({
  message,
  onRetry,
}: {
  message: string;
  onRetry?: () => void;
}) {
  return (
    <Card role="alert" className="border-destructive/50 bg-destructive/5">
      <CardContent className="flex flex-col items-start gap-3 p-4">
        <div className="flex items-start gap-2">
          <TriangleAlert className="mt-0.5 size-4 shrink-0 text-destructive" />
          <p className="text-sm">{message}</p>
        </div>

        {onRetry && (
          <Button size="sm" variant="outline" onClick={onRetry}>
            <RefreshCw className="size-3.5" />
            Tentar de novo
          </Button>
        )}
      </CardContent>
    </Card>
  );
}

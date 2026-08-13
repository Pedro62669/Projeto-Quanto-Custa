"use client";

import Link from "next/link";
import { Package } from "lucide-react";

import { Card, CardContent } from "@/components/ui/card";

/**
 * Moldura das telas sem sessão.
 *
 * Cadastro, recuperação e redefinição de senha compartilham o mesmo enquadre do
 * login: marca no topo, cartão único, largura curta. Repetir esse arranjo em
 * quatro arquivos faria as quatro telas divergirem aos poucos — e a diferença
 * apareceria justamente no fluxo de quem ainda não conhece o produto.
 */
export function TelaPublica({
  titulo,
  descricao,
  children,
  rodape,
  largura = "max-w-sm",
}: {
  titulo: string;
  descricao?: string;
  children: React.ReactNode;
  rodape?: React.ReactNode;
  largura?: string;
}) {
  return (
    <main className="flex min-h-dvh items-center justify-center bg-muted/30 p-6">
      <div className={`w-full space-y-6 ${largura}`}>
        <header className="space-y-2 text-center">
          <Link
            href="/login"
            className="mx-auto flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground"
            aria-label="Início"
          >
            <Package className="size-5" />
          </Link>
          <h1 className="text-xl font-semibold tracking-tight">{titulo}</h1>
          {descricao && <p className="text-sm text-muted-foreground">{descricao}</p>}
        </header>

        <Card>
          <CardContent className="p-6">{children}</CardContent>
        </Card>

        {rodape && <div className="text-center text-sm">{rodape}</div>}
      </div>
    </main>
  );
}

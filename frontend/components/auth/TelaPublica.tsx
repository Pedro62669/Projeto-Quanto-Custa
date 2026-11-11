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
    <main className="relative flex min-h-dvh items-center justify-center overflow-hidden p-6">
      {/*
        O mesmo fundo do herói da página de vendas: malha de desenho técnico e
        as duas manchas de cor. Quem clica em "Criar conta" lá chega aqui, e a
        tela precisa parecer a continuação daquela — não a porta dos fundos de
        outro sistema.
      */}
      <div aria-hidden className="pointer-events-none absolute inset-0">
        <div className="malha absolute inset-0" />
        <div className="deriva absolute -top-40 right-[-12%] size-[32rem] rounded-full bg-brand/14 blur-3xl" />
        <div className="deriva-lenta absolute bottom-[-22%] left-[-12%] size-[28rem] rounded-full bg-kraft/22 blur-3xl" />
      </div>

      <div className={`entra relative w-full space-y-6 ${largura}`}>
        <header className="space-y-2 text-center">
          {/* Quadrado de tinta, caixa azul — a logo, igual em todo lugar. Leva
              à página de vendas: quem chegou aqui por engano tem como voltar. */}
          <Link
            href="/"
            className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-primary shadow-elevado transition-[rotate] duration-300 hover:-rotate-6"
            aria-label="quantoCusta, início"
          >
            <Package className="size-6 text-brand-on-inverted" />
          </Link>
          <h1 className="pt-2 text-2xl font-semibold tracking-tight">{titulo}</h1>
          {descricao && <p className="text-sm text-muted-foreground">{descricao}</p>}
        </header>

        <Card className="shadow-elevado">
          <CardContent className="p-6">{children}</CardContent>
        </Card>

        {rodape && <div className="text-center text-sm">{rodape}</div>}
      </div>
    </main>
  );
}

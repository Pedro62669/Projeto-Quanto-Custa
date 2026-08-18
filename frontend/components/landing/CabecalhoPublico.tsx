"use client";

import Link from "next/link";
import { Package } from "lucide-react";

import { useSession } from "@/hooks/useSession";
import { cn } from "@/lib/utils";
import { ESTILO_CTA } from "./estilos";

/**
 * O cabeçalho da página de vendas.
 *
 * É cliente por um motivo só: quem já usa o sistema também chega pela raiz —
 * pelo favorito antigo, pelo link no e-mail, por digitar o domínio. Mostrar
 * "Criar conta grátis" para quem já tem conta é mandar a pessoa pelo caminho
 * mais longo até o próprio painel.
 *
 * A leitura da sessão passa por `useSession`, que usa `useSyncExternalStore` com
 * snapshot de servidor — sem isso, o localStorage lido na renderização faria o
 * HTML do servidor diferir do primeiro render do cliente, e o React descartaria
 * a árvore com erro de hidratação. O servidor sempre desenha "Entrar"; se houver
 * sessão, o botão troca na hidratação.
 */
export function CabecalhoPublico() {
  const usuario = useSession();

  return (
    <header className="sticky top-0 z-50 border-b border-border/60 bg-background/80 backdrop-blur-md">
      <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-5">
        <Link href="/" className="flex items-center gap-2.5" aria-label="quantoCusta, início">
          {/*
            Quadrado preto, caixa azul.

            Mesma divisão do menu e dos botões de criar: a superfície é neutra e
            o símbolo carrega a marca. `--brand-on-inverted` e não `--brand`
            porque o fundo aqui é `--primary`, que inverte com o tema — no
            escuro este quadrado fica quase branco.
          */}
          <span className="flex size-8 items-center justify-center rounded-lg bg-primary">
            <Package className="size-4 text-brand-on-inverted" />
          </span>
          <span className="text-[0.95rem] font-semibold tracking-tight">quantoCusta</span>
        </Link>

        <nav aria-label="Seções" className="hidden items-center gap-7 md:flex">
          {[
            ["#recursos", "Recursos"],
            ["#como-funciona", "Como funciona"],
            ["#precos", "Preços"],
            ["#perguntas", "Perguntas"],
          ].map(([href, rotulo]) => (
            <a
              key={href}
              href={href}
              className="text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
              {rotulo}
            </a>
          ))}
        </nav>

        <div className="flex items-center gap-2">
          {usuario ? (
            <Link href="/painel" className={cn(ESTILO_CTA, "h-9 px-4 text-sm")}>
              Ir para o painel
            </Link>
          ) : (
            <>
              <Link
                href="/login"
                className="hidden rounded-lg px-3 py-2 text-sm text-muted-foreground transition-colors hover:text-foreground sm:block"
              >
                Entrar
              </Link>
              <Link href="/cadastro" className={cn(ESTILO_CTA, "h-9 px-4 text-sm")}>
                Criar conta
              </Link>
            </>
          )}
        </div>
      </div>
    </header>
  );
}

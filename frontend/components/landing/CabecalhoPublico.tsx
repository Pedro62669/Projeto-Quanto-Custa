"use client";

import { useSyncExternalStore } from "react";
import Link from "next/link";
import { Package } from "lucide-react";

import { useSession } from "@/hooks/useSession";
import { cn } from "@/lib/utils";
import { ESTILO_CTA } from "./estilos";

/**
 * A página já saiu do topo?
 *
 * `useSyncExternalStore` e não `useState` + efeito, pela mesma razão de
 * `useSession`: a posição da rolagem é estado EXTERNO ao React. Lida num
 * efeito, ela chegaria um render atrasada e o cabeçalho piscaria transparente
 * ao recarregar a página no meio — que é exatamente onde o F5 costuma cair.
 *
 * O servidor sempre responde "no topo"; se não for o caso, a hidratação corrige
 * antes da primeira pintura.
 */
function assinaRolagem(aoMudar: () => void): () => void {
  window.addEventListener("scroll", aoMudar, { passive: true });

  return () => window.removeEventListener("scroll", aoMudar);
}

const rolou = () => window.scrollY > 12;
const noTopo = () => false;

/**
 * O cabeçalho da página de vendas.
 *
 * É cliente por dois motivos. O primeiro: quem já usa o sistema também chega
 * pela raiz — pelo favorito antigo, pelo link no e-mail, por digitar o domínio.
 * Mostrar "Criar conta grátis" para quem já tem conta é mandar a pessoa pelo
 * caminho mais longo até o próprio painel. O segundo é a rolagem: no topo ele é
 * transparente, para o herói começar na borda da janela, e ganha fundo e sombra
 * quando há conteúdo passando por baixo.
 *
 * A leitura da sessão passa por `useSession`, que usa `useSyncExternalStore` com
 * snapshot de servidor — sem isso, o localStorage lido na renderização faria o
 * HTML do servidor diferir do primeiro render do cliente, e o React descartaria
 * a árvore com erro de hidratação. O servidor sempre desenha "Entrar"; se houver
 * sessão, o botão troca na hidratação.
 */
export function CabecalhoPublico() {
  const usuario = useSession();
  const descolado = useSyncExternalStore(assinaRolagem, rolou, noTopo);

  return (
    <header
      className={cn(
        "sticky top-0 z-50 border-b transition-[background-color,border-color,box-shadow] duration-300",
        descolado
          ? "border-border/70 bg-background/80 shadow-xs backdrop-blur-md"
          : "border-transparent bg-transparent",
      )}
    >
      <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-5">
        <Link href="/" className="group flex items-center gap-2.5" aria-label="quantoCusta, início">
          {/*
            Quadrado de tinta, caixa azul.

            Mesma divisão do menu e dos botões de criar: a superfície é neutra e
            o símbolo carrega a marca. `--brand-on-inverted` e não `--brand`
            porque o fundo aqui é `--primary`, que inverte com o tema — no
            escuro este quadrado fica quase branco.
          */}
          <span className="flex size-8 items-center justify-center rounded-lg bg-primary shadow-xs transition-[rotate] duration-300 group-hover:-rotate-6">
            <Package className="size-4 text-brand-on-inverted" />
          </span>
          <span className="font-heading text-[1.05rem] font-semibold tracking-tight">
            quantoCusta
          </span>
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
              // O traço cresce da esquerda: mostra para onde o ponteiro foi sem
              // mudar o peso do texto, que faria os vizinhos se mexerem.
              className="relative py-1 text-sm text-muted-foreground transition-colors after:absolute after:inset-x-0 after:bottom-0 after:h-px after:origin-left after:scale-x-0 after:bg-brand after:transition-[scale] after:duration-300 hover:text-foreground hover:after:scale-x-100"
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

"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { ShieldCheck } from "lucide-react";

import { cn } from "@/lib/utils";
import { useAccount, selectIsAdmin, selectIsPlatformAdmin } from "@/store/useAccount";
import { NAVEGACAO, estaAtivo } from "./navegacao";

/**
 * Barra lateral de navegação.
 *
 * Itens de admin somem para quem não é admin em vez de aparecerem desabilitados:
 * um menu cheio de portas trancadas ensina o usuário a ignorar o menu. O
 * servidor barra de novo — a barra lateral organiza, não protege.
 */
export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const caminho = usePathname();
  const conta = useAccount((s) => s.account);
  const isAdmin = useAccount(selectIsAdmin);
  const isPlatformAdmin = useAccount(selectIsPlatformAdmin);

  /*
   * Sem saber quem é, não se desenha menu.
   *
   * Renderizar antes de `/api/me` responder mostrava o menu de quem NÃO é
   * admin, e os cinco itens restantes apareciam meio segundo depois — um
   * piscar que faz o usuário duvidar do que viu, e que só existe porque o
   * papel chega pela rede.
   */
  if (conta === null) {
    return (
      <div className="space-y-2 p-3" aria-busy aria-label="Carregando a navegação">
        {Array.from({ length: 8 }, (_, i) => (
          <div key={i} className="h-7 animate-pulse rounded-lg bg-muted" />
        ))}
      </div>
    );
  }

  return (
    <nav aria-label="Navegação principal" className="flex flex-col gap-5 p-3">
      {NAVEGACAO.map((grupo) => {
        const itens = grupo.items.filter((item) => !item.adminOnly || isAdmin);

        if (itens.length === 0) return null;

        return (
          <div key={grupo.label} className="space-y-1">
            <p className="px-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {grupo.label}
            </p>

            {itens.map((item) => {
              const ativo = estaAtivo(item.href, caminho);

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  onClick={onNavigate}
                  aria-current={ativo ? "page" : undefined}
                  className={cn(
                    "flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors",
                    ativo
                      ? "bg-foreground font-medium text-white"
                      : "text-muted-foreground hover:bg-muted/60 hover:text-foreground",
                  )}
                >
                  {/*
                    O ícone carrega a cor da marca; o rótulo fica branco.

                    A divisão de trabalho é essa: o branco garante a leitura
                    (5,7:1 sobre o preto), e o azul marca o lugar sem disputar
                    com o texto. Pintar os dois de azul foi a versão que ficou
                    apagada — o rótulo é o que se lê, e ele é pequeno.
                  */}
                  <item.icon
                    className={cn("size-4 shrink-0", ativo && "text-brand-on-dark")}
                  />
                  {item.label}
                </Link>
              );
            })}
          </div>
        );
      })}

      {/*
        O painel do SaaS não pertence a grupo nenhum dos de cima: ele é de outro
        público — quem opera a plataforma, não quem usa o produto.
      */}
      {isPlatformAdmin && (
        <div className="space-y-1 border-t pt-4">
          <Link
            href="/plataforma"
            onClick={onNavigate}
            aria-current={estaAtivo("/plataforma", caminho) ? "page" : undefined}
            className={cn(
              "flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors",
              estaAtivo("/plataforma", caminho)
                ? "bg-foreground font-medium text-white"
                : "text-muted-foreground hover:bg-muted/60 hover:text-foreground",
            )}
          >
            <ShieldCheck
              className={cn(
                "size-4 shrink-0",
                estaAtivo("/plataforma", caminho) && "text-brand-on-dark",
              )}
            />
            Plataforma
          </Link>
        </div>
      )}
    </nav>
  );
}

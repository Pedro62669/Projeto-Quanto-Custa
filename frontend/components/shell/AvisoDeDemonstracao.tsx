"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Lock } from "lucide-react";

import { Button } from "@/components/ui/button";
import { useAccount, selectSemPlano } from "@/store/useAccount";

/**
 * Faixa de modo demonstração — a conta existe e ainda não assinou.
 *
 * Mora na casca, e não em cada tela, porque a trava também mora: o servidor
 * recusa toda escrita de quem não tem plano, em qualquer rota. Um aviso só na
 * calculadora deixaria a pessoa clicar em "Novo material" e receber um 403 como
 * se fosse falha do sistema.
 *
 * Faixa, e não diálogo: a leitura continua liberada de propósito, e um modal
 * bloqueando a navegação impediria exatamente o que a demonstração existe para
 * permitir — olhar o produto inteiro antes de decidir.
 *
 * ## Por que ela some em /assinatura
 *
 * É a única tela onde a faixa não informa nada: a pessoa já está no lugar para
 * onde ela aponta, e o cartão de plano lá dentro diz a mesma coisa com mais
 * detalhe. Repetir o aviso acima do conteúdo que o repete empurra a tabela de
 * planos para baixo da dobra — na tela em que ela é a decisão.
 */
export function AvisoDeDemonstracao() {
  const semPlano = useAccount(selectSemPlano);
  const caminho = usePathname();

  if (!semPlano || caminho === "/assinatura") return null;

  return (
    <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-b border-brand/30 bg-brand/10 px-4 py-1.5 text-xs text-brand-strong">
      <span className="flex items-center gap-1.5">
        <Lock className="size-3.5 shrink-0" aria-hidden />
        <strong className="font-medium">Modo demonstração.</strong>
        Você navega e vê tudo; para gravar orçamentos, materiais e lançamentos,
        escolha um plano.
      </span>

      <Button asChild variant="ghost" size="xs" className="h-6 hover:bg-brand/20">
        <Link href="/assinatura">Ver os planos</Link>
      </Button>
    </div>
  );
}

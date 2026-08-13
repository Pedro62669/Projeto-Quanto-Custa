"use client";

import { useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";

import { TooltipProvider } from "@/components/ui/tooltip";
import { Skeleton } from "@/components/ui/skeleton";
import { AppHeader } from "@/components/shell/AppHeader";
import { Sidebar } from "@/components/shell/Sidebar";
import { AvisoDeVerificacao } from "@/components/shell/AvisoDeVerificacao";
import { session } from "@/lib/auth";
import { useAccount } from "@/store/useAccount";

/**
 * Casca autenticada — tudo que exige sessão vive dentro deste grupo de rotas.
 *
 * O guard mora AQUI, e não em cada página, porque copiado ele diverge: a décima
 * tela é a que esquece de checar, e ela é justamente a que ninguém testa. O
 * grupo `(app)` não aparece na URL, então `/calculadora` continua sendo
 * `/calculadora`.
 *
 * Não substitui a proteção do servidor: sem token, toda requisição volta 401 e
 * o cliente HTTP derruba a sessão. Este guard evita a tela piscar antes disso.
 */
export default function AppLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const caminho = usePathname();

  const carregaConta = useAccount((s) => s.load);
  const carregando = useAccount((s) => s.loading);
  const conta = useAccount((s) => s.account);

  useEffect(() => {
    if (!session.isAuthenticated) {
      router.replace("/login");

      return;
    }

    void carregaConta();
  }, [carregaConta, router]);

  /*
   * A calculadora ocupa a altura exata da janela e rola por dentro (três
   * colunas independentes). As outras telas rolam a página inteira. Tratar as
   * duas iguais quebraria uma delas: com rolagem da página, o Canvas 3D perde a
   * altura; com altura travada, uma tabela de 300 linhas fica inalcançável.
   */
  const telaCheia = caminho === "/calculadora";

  /*
   * O guard NÃO pode decidir o que renderizar.
   *
   * Havia aqui um `if (!session.isAuthenticated) return null`. A condição lê o
   * localStorage, que não existe no servidor: a rota era pré-renderizada como
   * vazia e hidratava como a casca inteira, e o React descartava a árvore com
   * "Hydration failed" a cada carga cheia — visível como um F5 que remontava
   * tudo, e invisível para qualquer teste que só navegue pelo menu.
   *
   * A casca é sempre a mesma nos dois lados; quem não tem sessão é levado ao
   * login pelo efeito acima, e enquanto isso vê apenas o esqueleto — nenhum
   * dado é buscado sem token.
   */
  return (
    <TooltipProvider delayDuration={200}>
      <div
        data-slot="app-shell"
        className={
          telaCheia
            ? "flex h-dvh flex-col overflow-hidden bg-background"
            : "flex min-h-dvh flex-col bg-background"
        }
      >
        <AvisoDeVerificacao />
        <AppHeader />

        <div className="flex min-h-0 flex-1">
          <aside
            data-slot="app-sidebar"
            className="hidden w-56 shrink-0 border-r lg:block lg:overflow-y-auto"
          >
            <Sidebar />
          </aside>

          <main
            data-slot="app-main"
            className={telaCheia ? "min-w-0 flex-1" : "min-w-0 flex-1 p-4 sm:p-6"}
          >
            {carregando && conta === null ? <ShellSkeleton /> : children}
          </main>
        </div>
      </div>
    </TooltipProvider>
  );
}

function ShellSkeleton() {
  return (
    <div className="space-y-4 p-6">
      <Skeleton className="h-8 w-52" />
      <Skeleton className="h-40 w-full" />
    </div>
  );
}

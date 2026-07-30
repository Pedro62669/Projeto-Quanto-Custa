"use client";

import { useEffect } from "react";
import dynamic from "next/dynamic";
import { useRouter } from "next/navigation";
import { useShallow } from "zustand/react/shallow";
import { LogOut } from "lucide-react";

import { Button } from "@/components/ui/button";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Skeleton } from "@/components/ui/skeleton";
import { Separator } from "@/components/ui/separator";
import { TooltipProvider } from "@/components/ui/tooltip";

import { session, type SessionUser } from "@/lib/auth";
import { useSession } from "@/hooks/useSession";

import { DimensionForm } from "@/components/calculator/DimensionForm";
import { PriceSummary } from "@/components/calculator/PriceSummary";
import { SaveQuoteDialog } from "@/components/calculator/SaveQuoteDialog";

import { useServerSync } from "@/hooks/useServerSync";
import {
  useQuoteStore,
  selectDimensions,
  selectActiveMaterial,
} from "@/store/useQuoteStore";

/**
 * O Canvas 3D é carregado apenas no cliente.
 *
 * `ssr: false` não é opcional: three.js toca em WebGL/`window` e quebraria na
 * renderização do servidor. O import dinâmico também mantém o bundle inicial
 * enxuto — three + drei são pesados, e a tela precisa pintar o formulário
 * antes do modelo estar pronto.
 */
const BoxViewer = dynamic(
  () => import("@/components/three/BoxViewer").then((m) => m.BoxViewer),
  {
    ssr: false,
    loading: () => <Skeleton className="size-full rounded-none" />,
  },
);

/**
 * Calculadora de orçamento — layout split screen.
 *
 * Esquerda: entrada e resultado financeiro (coluna de largura fixa, scroll
 * próprio). Direita: visualização 3D ocupando todo o espaço restante.
 *
 * A divisão espelha o modelo mental do usuário: à esquerda ele DECIDE, à
 * direita ele VERIFICA. Como as duas colunas rolam de forma independente, o
 * modelo 3D nunca sai de vista enquanto o formulário é percorrido.
 */
export default function CalculadoraPage() {
  const router = useRouter();
  const bootstrap = useQuoteStore((s) => s.bootstrap);
  const isBootstrapping = useQuoteStore((s) => s.isBootstrapping);

  // Lida via useSyncExternalStore: localStorage é store externa ao React.
  const user = useSession();

  // Assina somente as dimensões e o material: digitar o nome do cliente ou
  // mexer na margem NÃO re-renderiza o Canvas.
  const dimensions = useQuoteStore(useShallow(selectDimensions));
  const material = useQuoteStore(useShallow(selectActiveMaterial));

  const hasResult = useQuoteStore((s) => s.preview !== null || s.confirmed !== null);

  /**
   * Guard de rota: sem token, nada é buscado e o usuário vai para o login.
   *
   * Roda em efeito porque `router.replace` é uma ação sobre um sistema externo
   * (o histórico do navegador) — que é justamente o papel de um efeito.
   */
  useEffect(() => {
    if (!session.isAuthenticated) {
      router.replace("/login");
      return;
    }

    void bootstrap();
  }, [bootstrap, router]);

  // Mantém o servidor sincronizado em debounce a cada mudança da especificação.
  useServerSync();

  return (
    <TooltipProvider delayDuration={200}>
      <div className="flex h-dvh flex-col overflow-hidden bg-background">
        <Header
          user={user}
          onLogout={async () => {
            await session.logout();
            router.replace("/login");
          }}
        />

        {/* Split screen: empilhado no mobile, lado a lado a partir de lg.
            `min-h-0` é o que permite o scroll interno das colunas funcionar
            dentro de um container flex. */}
        <main className="grid min-h-0 flex-1 grid-rows-[auto_1fr] lg:grid-cols-[minmax(380px,440px)_1fr] lg:grid-rows-1">
          {/* ── Coluna esquerda: entrada + resultados ─────────────────── */}
          <ScrollArea className="border-b lg:border-b-0 lg:border-r">
            <div className="space-y-6 p-6">
              {isBootstrapping ? (
                <FormSkeleton />
              ) : (
                <>
                  <DimensionForm />
                  <Separator />
                  <PriceSummary />
                  <SaveQuoteDialog disabled={!hasResult} />
                </>
              )}
            </div>
          </ScrollArea>

          {/* ── Coluna direita: visualização 3D ───────────────────────── */}
          <section className="relative min-h-[320px] bg-gradient-to-b from-muted/40 to-muted/10 lg:min-h-0">
            <BoxViewer
              widthMm={dimensions.widthMm}
              heightMm={dimensions.heightMm}
              depthMm={dimensions.depthMm}
              boxModel={dimensions.boxModel}
              // Espessura real do material: vira a espessura visível das
              // paredes, para que a caixa oca mostre o que foi escolhido.
              thicknessMm={material?.thickness_mm}
              colorHex={material?.color_hex}
              textureUrl={material?.texture_url}
              materialLabel={material?.name}
            />
          </section>
        </main>
      </div>
    </TooltipProvider>
  );
}

function Header({
  user,
  onLogout,
}: {
  user: SessionUser | null;
  onLogout: () => void;
}) {
  return (
    <header className="flex h-14 shrink-0 items-center justify-between border-b px-6">
      <div>
        <h1 className="text-sm font-semibold">Calculadora de embalagens</h1>
        <p className="text-xs text-muted-foreground">
          Dimensione, precifique e salve o orçamento
        </p>
      </div>

      {user && (
        <div className="flex items-center gap-3">
          <span className="hidden text-xs text-muted-foreground sm:inline">
            {user.name}
          </span>
          <Button variant="ghost" size="sm" onClick={onLogout} aria-label="Sair">
            <LogOut className="size-4" />
          </Button>
        </div>
      )}
    </header>
  );
}

function FormSkeleton() {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-3 gap-3">
        <Skeleton className="h-16" />
        <Skeleton className="h-16" />
        <Skeleton className="h-16" />
      </div>
      <Skeleton className="h-10 w-full" />
      <Skeleton className="h-28 w-full" />
    </div>
  );
}

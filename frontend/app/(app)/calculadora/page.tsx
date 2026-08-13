"use client";

import { useEffect } from "react";
import dynamic from "next/dynamic";
import { useShallow } from "zustand/react/shallow";
import { RefreshCw, RotateCcw, TriangleAlert } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Separator } from "@/components/ui/separator";

import { DimensionForm } from "@/components/calculator/DimensionForm";
import { PartsPreview } from "@/components/calculator/PartsPreview";
import { PriceSummary } from "@/components/calculator/PriceSummary";
import { SaveQuoteDialog } from "@/components/calculator/SaveQuoteDialog";

import { useServerSync } from "@/hooks/useServerSync";
import {
  useQuoteStore,
  selectDimensions,
  selectActiveMaterial,
  selectBootstrapFailed,
  selectIsFreeModel,
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
 * Calculadora de orçamento — três colunas.
 *
 * Esquerda o que se DECIDE (o formulário), centro o que se VERIFICA (a peça em
 * 3D), direita o que se CONCLUI (preço, composição e as ações). A separação
 * espelha a ordem em que o trabalho acontece, e é o que permite mexer numa
 * medida sem perder o preço de vista.
 *
 * No celular a ordem muda: a peça vem primeiro, depois o formulário, depois o
 * preço. Cada coluna rola por conta própria a partir de `lg`; abaixo disso a
 * página inteira rola, porque três áreas de rolagem independentes numa tela de
 * telefone é o tipo de coisa que prende o dedo do usuário na área errada.
 *
 * Guard de sessão e cabeçalho vivem na casca (`app/(app)/layout.tsx`) — esta
 * página cuida só de calcular.
 */
export default function CalculadoraPage() {
  const bootstrap = useQuoteStore((s) => s.bootstrap);
  const isBootstrapping = useQuoteStore((s) => s.isBootstrapping);
  const bootstrapFailed = useQuoteStore(selectBootstrapFailed);

  // Assina somente as dimensões e o material: digitar o nome do cliente ou
  // mexer na margem NÃO re-renderiza o Canvas.
  const dimensions = useQuoteStore(useShallow(selectDimensions));
  const material = useQuoteStore(useShallow(selectActiveMaterial));

  const hasResult = useQuoteStore((s) => s.preview !== null || s.confirmed !== null);

  // No modelo livre não há geometria a renderizar — e não montar o <BoxViewer />
  // significa que three.js e drei nem chegam a ser baixados (ver a nota do
  // import dinâmico acima).
  const modeloLivre = useQuoteStore(selectIsFreeModel);

  useEffect(() => {
    void bootstrap();
  }, [bootstrap]);

  // Mantém o servidor sincronizado em debounce a cada mudança da especificação.
  useServerSync();

  if (bootstrapFailed) {
    return <BootstrapError onRetry={() => void bootstrap()} />;
  }

  return (
    <div
      className="
        grid h-full grid-cols-1
        lg:min-h-0 lg:grid-cols-[380px_minmax(0,1fr)_400px]
      "
    >
      {/* ── Verificar: a peça ───────────────────────────────────────────
          Primeira no celular, central no desktop. */}
      <section className="order-1 min-h-[320px] border-b bg-gradient-to-b from-muted/40 to-muted/10 lg:order-2 lg:min-h-0 lg:border-x lg:border-b-0">
        {isBootstrapping ? (
          <Skeleton className="size-full rounded-none" />
        ) : modeloLivre ? (
          <PartsPreview />
        ) : (
          <BoxViewer
            widthMm={dimensions.widthMm}
            heightMm={dimensions.heightMm}
            depthMm={dimensions.depthMm}
            boxModel={dimensions.boxModel}
            lidWidthMm={dimensions.lidWidthMm}
            lidDepthMm={dimensions.lidDepthMm}
            lidHeightMm={dimensions.lidHeightMm}
            // Espessura real do material: vira a espessura visível das
            // paredes, para que a caixa oca mostre o que foi escolhido.
            thicknessMm={material?.thickness_mm}
            colorHex={material?.color_hex}
            textureUrl={material?.texture_url}
            materialLabel={material?.name}
          />
        )}
      </section>

      {/* ── Decidir: a especificação ────────────────────────────────────── */}
      <div className="order-2 p-6 lg:order-1 lg:h-full lg:overflow-y-auto">
        {isBootstrapping ? <FormSkeleton /> : <DimensionForm />}
      </div>

      {/* ── Concluir: preço e ações ─────────────────────────────────────── */}
      <div className="order-3 space-y-6 border-t p-6 lg:h-full lg:overflow-y-auto lg:border-t-0">
        {isBootstrapping ? (
          <SummarySkeleton />
        ) : (
          <>
            <PriceSummary />
            <Separator />
            <ActionPanel disabled={!hasResult} />
          </>
        )}
      </div>
    </div>
  );
}

/**
 * Painel de ações.
 *
 * "Recomeçar" ao lado de "Salvar" e visualmente subordinado a ele: é uma ação
 * destrutiva que apaga o que foi digitado, e não deve ter o mesmo peso do
 * botão que conclui o trabalho.
 */
function ActionPanel({ disabled }: { disabled: boolean }) {
  const reset = useQuoteStore((s) => s.reset);

  return (
    <div className="space-y-2">
      <SaveQuoteDialog disabled={disabled} />

      <Button
        variant="ghost"
        size="sm"
        className="w-full text-muted-foreground"
        onClick={reset}
      >
        <RotateCcw className="size-3.5" />
        Recomeçar
      </Button>
    </div>
  );
}

/**
 * Falha no carregamento inicial.
 *
 * Sem os parâmetros de custo não existe cálculo, então não há tela parcial a
 * mostrar — mas há uma saída: repetir. Antes disso, um erro no bootstrap
 * deixava um formulário vazio e mudo, e a única forma de sair era recarregar a
 * página na mão.
 */
function BootstrapError({ onRetry }: { onRetry: () => void }) {
  const mensagem = useQuoteStore((s) => s.error);

  return (
    <div className="flex h-full items-center justify-center p-6">
      <Card role="alert" className="max-w-md border-destructive/50 bg-destructive/5">
        <CardContent className="space-y-3 p-5">
          <div className="flex items-start gap-2">
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-destructive" />
            <div className="space-y-1">
              <h2 className="text-sm font-semibold">
                Não foi possível carregar a calculadora
              </h2>
              <p className="text-xs text-muted-foreground">
                {mensagem ?? "Falha ao buscar os materiais e os parâmetros de custo."}
              </p>
            </div>
          </div>

          <Button size="sm" onClick={onRetry} className="w-full">
            <RefreshCw className="size-3.5" />
            Tentar de novo
          </Button>
        </CardContent>
      </Card>
    </div>
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

function SummarySkeleton() {
  return (
    <div className="space-y-4">
      <Skeleton className="h-28 w-full" />
      <div className="grid grid-cols-2 gap-3">
        <Skeleton className="h-20" />
        <Skeleton className="h-20" />
      </div>
      <Skeleton className="h-40 w-full" />
    </div>
  );
}

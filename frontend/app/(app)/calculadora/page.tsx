"use client";

import { useEffect } from "react";
import dynamic from "next/dynamic";
import { useShallow } from "zustand/react/shallow";
import Link from "next/link";
import { Lock, RefreshCw, RotateCcw, TriangleAlert } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Separator } from "@/components/ui/separator";

import { DimensionForm } from "@/components/calculator/DimensionForm";
import { PartsPreview } from "@/components/calculator/PartsPreview";
import { PriceSummary } from "@/components/calculator/PriceSummary";
import { SaveQuoteDialog } from "@/components/calculator/SaveQuoteDialog";

import { useServerSync } from "@/hooks/useServerSync";
import { useReabrirOrcamento, type Reabertura } from "@/hooks/useReabrirOrcamento";
import {
  useQuoteStore,
  selectDimensions,
  selectActiveMaterial,
  selectBootstrapFailed,
  selectDemoMode,
  selectIsFreeModel,
} from "@/store/useQuoteStore";
import { useAccount, selectSemPlano } from "@/store/useAccount";

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

  /*
   * Sem assinatura a calculadora vira demonstração: a caixa de exemplo do
   * servidor, precificada de verdade, com tudo congelado. Ver
   * `carregarDemonstracao` na store.
   */
  const semPlano = useAccount(selectSemPlano);
  const contaResolvida = useAccount((s) => !s.loading);
  const demo = useQuoteStore(selectDemoMode);
  const carregarDemonstracao = useQuoteStore((s) => s.carregarDemonstracao);

  useEffect(() => {
    void bootstrap();
  }, [bootstrap]);

  // Depois do bootstrap: a prévia precisa dos materiais carregados para pintar
  // a caixa com a cor e a espessura do papelão certo.
  useEffect(() => {
    if (semPlano && !isBootstrapping) void carregarDemonstracao();
  }, [semPlano, isBootstrapping, carregarDemonstracao]);

  // `?duplicar=12` ou `?editar=12` reabrem um orçamento gravado. Depende do
  // bootstrap: sem materiais carregados o formulário abriria sem o material
  // selecionado.
  const reabertura = useReabrirOrcamento(!isBootstrapping);

  /*
   * Mantém o servidor sincronizado em debounce a cada mudança da especificação.
   *
   * Só depois de saber se há plano. Antes disso, a conta sem assinatura levava
   * um 403 do `simulate` e a tela pintava um erro vermelho ao lado da
   * demonstração funcionando — ver a nota do hook.
   */
  useServerSync({ ativo: contaResolvida && !semPlano });

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
      <div className="order-2 space-y-4 p-6 lg:order-1 lg:h-full lg:overflow-y-auto">
        {demo && <AvisoDeDemonstracao />}

        {isBootstrapping ? (
          <FormSkeleton />
        ) : demo ? (
          /*
            Congelado por DUAS vias, e nenhuma delas sozinha basta.

            `fieldset disabled` desliga todo controle nativo lá dentro — input,
            select, button — inclusive por teclado, e é a que o leitor de tela
            anuncia. `pointer-events-none` cobre o que não é controle nativo: o
            slider do Radix é um span com role="slider" e o fieldset não o
            alcança.

            `display: contents` no fieldset porque ele é uma caixa de bloco com
            estilo próprio de navegador, e envolvê-lo cru quebraria o
            espaçamento da coluna.
          */
          <fieldset disabled className="contents">
            <div className="pointer-events-none">
              <DimensionForm />
            </div>
          </fieldset>
        ) : (
          <DimensionForm />
        )}
      </div>

      {/* ── Concluir: preço e ações ─────────────────────────────────────── */}
      <div className="order-3 space-y-6 border-t p-6 lg:h-full lg:overflow-y-auto lg:border-t-0">
        {isBootstrapping ? (
          <SummarySkeleton />
        ) : (
          <>
            <PriceSummary />
            <Separator />
            {/* Sem plano não há o que salvar: o botão sumiria em 403. O lugar
                da conversa é o aviso da coluna da esquerda. */}
            {!demo && <ActionPanel disabled={!hasResult} reabertura={reabertura} />}
          </>
        )}
      </div>
    </div>
  );
}

/**
 * O aviso do modo demonstração.
 *
 * Fica ACIMA do formulário, e não sobre ele: uma tarja por cima esconderia
 * justamente o que a pessoa entrou para ver. O papel do aviso é explicar por que
 * os campos não respondem — sem ele, a tela congelada é indistinguível de uma
 * tela quebrada, e a primeira impressão do produto vira um bug.
 */
function AvisoDeDemonstracao() {
  return (
    <div className="rounded-lg border border-brand/40 bg-brand/5 p-4">
      <p className="flex items-center gap-2 text-sm font-medium">
        <Lock className="size-4 text-brand" aria-hidden />
        Demonstração
      </p>

      <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
        Esta é uma caixa de exemplo, com o preço calculado pelos seus custos
        cadastrados. Os campos ficam congelados até você escolher um plano —
        aí eles passam a valer para as suas caixas.
      </p>

      <Button asChild size="sm" className="mt-3 w-full">
        <Link href="/assinatura">Ver os planos</Link>
      </Button>
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
function ActionPanel({
  disabled,
  reabertura,
}: {
  disabled: boolean;
  reabertura: Reabertura | null;
}) {
  const reset = useQuoteStore((s) => s.reset);

  return (
    <div className="space-y-2">
      {/* Que orçamento está aberto, e o que o botão vai fazer com ele. Sem este
          aviso, "Salvar" numa edição parece criar mais um — e a pessoa acaba
          com dois orçamentos quando queria corrigir um. */}
      {reabertura && (
        <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
          {reabertura.modo === "editar" ? "Editando " : "Cópia de "}
          <span className="font-mono font-medium text-foreground">
            {reabertura.referencia}
          </span>
          {reabertura.modo === "editar"
            ? " — salvar grava por cima."
            : " — salvar cria um orçamento novo."}
        </p>
      )}

      <SaveQuoteDialog disabled={disabled} reabertura={reabertura} />

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

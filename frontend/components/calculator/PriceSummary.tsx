"use client";

import { useShallow } from "zustand/react/shallow";
import { Loader2, TriangleAlert } from "lucide-react";

import { Card, CardContent } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";

import {
  useQuoteStore,
  selectResult,
  selectCustomParts,
  selectIsFreeModel,
} from "@/store/useQuoteStore";
import { formatCurrency, isCylindrical } from "@/lib/pricing/engine";
import type { PricingBreakdown } from "@/lib/pricing/types";

/**
 * Painel financeiro.
 *
 * Um número domina a tela (o preço total) e o resto é subordinado a ele. A
 * decomposição do custo é secundária: existe para dar confiança no número
 * principal, não para competir com ele.
 */
export function PriceSummary() {
  const {
    result,
    currency,
    isSyncing,
    isConfirmed,
    error,
    isEngineStale,
    quantity,
    cilindrico,
    modeloLivre,
    totalDePecas,
  } = useQuoteStore(
    useShallow((s) => ({
      result: selectResult(s),
      currency: s.currency,
      isSyncing: s.isSyncing,
      isConfirmed: s.confirmed !== null,
      error: s.error,
      isEngineStale: s.isEngineStale,
      quantity: s.spec.quantity,
      cilindrico: isCylindrical(s.spec.box_model),
      modeloLivre: selectIsFreeModel(s),

      // Peças distintas × quantidade de cada uma: é o que a bancada vai cortar
      // por caixa, e o número que dá escala ao trabalho por trás do preço.
      totalDePecas: selectCustomParts(s).reduce((soma, p) => soma + p.quantity, 0),
    })),
  );

  if (!result) return <PriceSummarySkeleton />;

  const money = (value: number, digits = 2) => formatCurrency(value, currency, digits);

  return (
    <div className="space-y-4">
      {error && (
        <Card className="border-destructive/50 bg-destructive/5">
          <CardContent className="flex items-start gap-2 p-3 text-xs text-destructive">
            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
            <span>{error}</span>
          </CardContent>
        </Card>
      )}

      {isEngineStale && (
        <Card className="border-amber-500/50 bg-amber-500/5">
          <CardContent className="p-3 text-xs text-amber-700 dark:text-amber-500">
            A versão do cálculo mudou no servidor. Recarregue a página para
            garantir que os valores exibidos estejam corretos.
          </CardContent>
        </Card>
      )}

      {/* ── Destaque: preço final ────────────────────────────────────────── */}
      <Card className="border-primary/20 bg-primary/5">
        <CardContent className="space-y-1 p-5">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              Preço de venda
            </span>

            {/* Sinaliza que o número na tela ainda é o preview local e está
                sendo confirmado pelo servidor — sem bloquear a leitura. */}
            {isSyncing ? (
              <Badge variant="outline" className="gap-1 text-[10px] font-normal">
                <Loader2 className="size-3 animate-spin" />
                validando
              </Badge>
            ) : (
              isConfirmed && (
                <Badge variant="secondary" className="text-[10px] font-normal">
                  confirmado
                </Badge>
              )
            )}
          </div>

          <p className="font-mono text-4xl font-bold tabular-nums tracking-tight">
            {money(result.total_price)}
          </p>

          <p className="text-sm text-muted-foreground">
            {money(result.unit_price, 4)} por unidade ·{" "}
            {quantity.toLocaleString("pt-BR")} un.
          </p>
        </CardContent>
      </Card>

      {/* ── Custo x lucro ────────────────────────────────────────────────── */}
      <div className="grid grid-cols-2 gap-3">
        <MetricCard label="Custo total (CMV)" value={money(result.total_cost)} />
        <MetricCard
          label="Lucro"
          value={money(result.profit_amount)}
          badge={<MargemBadge percentual={result.effective_margin_percent} />}
          emphasis={result.profit_amount > 0 ? "positive" : "negative"}
        />
      </div>

      {/* ── Para onde vai o preço ────────────────────────────────────────── */}
      <CostDistribution result={result} quantity={quantity} currency={currency} />

      {/* ── Composição do custo ──────────────────────────────────────────── */}
      <Card>
        <CardContent className="space-y-2.5 p-4">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Composição por unidade
          </h3>

          <CostLine
            label={modeloLivre ? "Estrutura" : "Matéria-prima"}
            value={money(result.material_cost, 4)}
          />

          {/*
            As três linhas que faltavam. Sem elas, a soma visível não fechava com
            o custo unitário logo abaixo — e no modelo livre, onde revestimento é
            um papel de peça declarado pelo usuário, o custo dele sumiria da tela
            depois de ele mesmo tê-lo digitado.
          */}
          {result.wrap_cost > 0 && (
            <CostLine label="Revestimento" value={money(result.wrap_cost, 4)} />
          )}
          {result.hardware_cost > 0 && (
            <CostLine label="Ferragem" value={money(result.hardware_cost, 4)} />
          )}
          {result.cradle_cost > 0 && (
            <CostLine label="Berço" value={money(result.cradle_cost, 4)} />
          )}

          <CostLine label="Mão de obra" value={money(result.labor_cost, 4)} />
          <CostLine
            label="Hora-máquina"
            value={money(result.machine_cost, 4)}
            /*
             * A depreciação NÃO tem linha própria, e isso é deliberado. No modo
             * hora-empresa ela já está dentro do custo do minuto, que multiplica
             * os minutos de produção — uma linha separada apareceria somada duas
             * vezes na mesma tela.
             */
            hint="Manutenção e uso do equipamento. Quando a empresa calcula pelo custo hora-empresa, a depreciação do parque já entra pelo minuto de produção — por isso ela não aparece como linha separada aqui."
          />
          <CostLine label="Energia" value={money(result.energy_cost, 4)} />
          {result.overhead_cost > 0 && (
            <CostLine label="Custos indiretos" value={money(result.overhead_cost, 4)} />
          )}

          <Separator />

          <CostLine label="Custo unitário" value={money(result.unit_cost, 4)} bold />
        </CardContent>
      </Card>

      {/* ── Consumo de material ──────────────────────────────────────────── */}
      <Card>
        <CardContent className="space-y-2.5 p-4">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Consumo de material
          </h3>

          {/*
            No modelo livre não existe UM retângulo: existem N, e o motor devolve
            0 × 0 justamente para não fingir que existe. Mostrar esse zero seria
            exibir uma medida inventada — a linha vira a contagem das peças, que
            é o que descreve o consumo aqui.
          */}
          {modeloLivre ? (
            <CostLine
              label="Peças por caixa"
              value={`${totalDePecas}`}
              hint="Somadas as quantidades de cada linha. Cada peça consome a área que foi medida, com a perda do próprio material."
            />
          ) : (
            <CostLine
              label="Plano de corte"
              value={`${result.blank_width_mm} × ${result.blank_height_mm} mm`}
              hint="Retângulo de material que cada peça consome, já com abas de colagem e fechamento."
            />
          )}

          {/* Só aparece nos modelos com tampa separada — nos demais, os
              campos vêm nulos da API e a linha não faz sentido. */}
          {result.lid_width_mm !== null && (
            <CostLine
              label={cilindrico ? "Tampa (Ø × A)" : "Tampa (L × P × A)"}
              value={
                cilindrico
                  ? `Ø${result.lid_width_mm} × ${result.lid_height_mm} mm`
                  : `${result.lid_width_mm} × ${result.lid_depth_mm} × ${result.lid_height_mm} mm`
              }
              hint="Medidas externas da tampa. Já incluem a folga de encaixe e a espessura do material, para que ela deslize sobre a base."
            />
          )}
          <CostLine
            label={modeloLivre ? "Estrutura por caixa" : "Área por unidade"}
            value={`${result.area_m2_per_unit.toFixed(4)} m²`}
          />

          {/* O revestimento é área à parte: consome mais folha que a estrutura
              (vira sobre as bordas) e custa outro preço por m². */}
          {result.wrap_area_m2_per_unit > 0 && (
            <CostLine
              label="Revestimento por caixa"
              value={`${result.wrap_area_m2_per_unit.toFixed(4)} m²`}
            />
          )}

          <CostLine
            label={modeloLivre ? "Estrutura no lote" : "Área total"}
            value={`${result.area_m2_total.toFixed(2)} m²`}
            hint="Inclui o percentual de desperdício e a quantidade do pedido."
          />
        </CardContent>
      </Card>
    </div>
  );
}

function MetricCard({
  label,
  value,
  hint,
  badge,
  emphasis,
}: {
  label: string;
  value: string;
  hint?: string;
  badge?: React.ReactNode;
  emphasis?: "positive" | "negative";
}) {
  const tone =
    emphasis === "positive"
      ? "text-emerald-600 dark:text-emerald-500"
      : emphasis === "negative"
        ? "text-destructive"
        : "";

  return (
    <Card>
      <CardContent className="space-y-0.5 p-4">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className={`font-mono text-lg font-semibold tabular-nums ${tone}`}>{value}</p>
        {badge}
        {hint && <p className="text-[11px] text-muted-foreground">{hint}</p>}
      </CardContent>
    </Card>
  );
}

/**
 * Margem real, com a cor dizendo se ela sustenta o negócio.
 *
 * As faixas não são enfeite: numa cartonagem, margem abaixo de 15% não cobre um
 * pedido que atrasa, e abaixo de 5% um único refazimento já leva o lucro do lote
 * inteiro. Quem precifica no aperto costuma ver só o preço final — a cor é o que
 * põe a consequência no mesmo campo de visão.
 *
 * A margem REAL, e não a digitada: no modo "sobre o custo" o usuário pede 30% e
 * recebe 23% de margem sobre a venda, e é a segunda que paga as contas.
 */
function MargemBadge({ percentual }: { percentual: number }) {
  const critica = percentual < 5;
  const apertada = !critica && percentual < 15;

  const tom = critica
    ? "bg-destructive/10 text-destructive"
    : apertada
      ? "bg-amber-500/15 text-amber-700 dark:text-amber-500"
      : "bg-muted text-muted-foreground";

  return (
    <span
      className={`inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium ${tom}`}
    >
      {(critica || apertada) && <TriangleAlert className="size-3" aria-hidden />}
      <span className="font-mono tabular-nums">{percentual}</span>% de margem real
    </span>
  );
}

/**
 * As fatias do preço, em uma barra.
 *
 * Sobre o PREÇO e não sobre o custo: sobre o custo as fatias sempre somariam
 * 100% de custo e não diriam nada sobre o negócio. Assim a barra responde a
 * pergunta que o dono da cartonagem faz — "estou vendendo papelão, vendendo
 * trabalho, ou sobrando alguma coisa?".
 *
 * O lucro é o RESÍDUO, calculado como o que sobra depois das outras fatias, e
 * não somado à parte. É a única forma de a barra fechar exatamente em 100%
 * mesmo com os arredondamentos de cada linha — uma barra de composição que não
 * fecha é uma barra que esconde para onde foi a diferença.
 */
function CostDistribution({
  result,
  quantity,
  currency,
}: {
  result: PricingBreakdown;
  quantity: number;
  currency: string;
}) {
  const total = result.total_price;

  if (total <= 0) return null;

  const porLote = (porUnidade: number) => porUnidade * quantity;

  const insumos = porLote(
    result.material_cost +
      result.wrap_cost +
      result.hardware_cost +
      result.cradle_cost,
  );
  const trabalho = porLote(result.labor_cost);
  const operacao = porLote(
    result.machine_cost + result.energy_cost + result.overhead_cost,
  );
  const impostos = result.tax_amount;

  const lucro = Math.max(total - insumos - trabalho - operacao - impostos, 0);

  const fatias = [
    { rotulo: "Insumos", valor: insumos, cor: "bg-slate-400" },
    { rotulo: "Trabalho", valor: trabalho, cor: "bg-blue-500" },
    { rotulo: "Operação", valor: operacao, cor: "bg-violet-400" },
    { rotulo: "Impostos", valor: impostos, cor: "bg-amber-400" },
    { rotulo: "Lucro", valor: lucro, cor: "bg-emerald-500" },
  ]
    .map((f) => ({ ...f, percentual: (f.valor / total) * 100 }))
    .filter((f) => f.valor > 0);

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Para onde vai o preço
        </h3>

        {/*
          role="img" com um rótulo que diz os números: um leitor de tela não
          enxerga proporção de barra, e a legenda logo abaixo já está no DOM
          para quem lê texto.
        */}
        <div
          role="img"
          aria-label={fatias
            .map((f) => `${f.rotulo}: ${f.percentual.toFixed(1)}%`)
            .join(", ")}
          className="flex h-2.5 w-full overflow-hidden rounded-full bg-muted"
        >
          {fatias.map((fatia) => (
            <div
              key={fatia.rotulo}
              className={fatia.cor}
              style={{ width: `${fatia.percentual}%` }}
            />
          ))}
        </div>

        <ul className="grid grid-cols-2 gap-x-3 gap-y-1.5">
          {fatias.map((fatia) => (
            <li key={fatia.rotulo} className="flex items-center gap-1.5 text-xs">
              <span
                className={`size-2 shrink-0 rounded-full ${fatia.cor}`}
                aria-hidden
              />
              <span className="text-muted-foreground">{fatia.rotulo}</span>
              <span className="ml-auto font-mono tabular-nums">
                {fatia.percentual.toFixed(1)}%
              </span>
            </li>
          ))}
        </ul>

        <p className="text-[11px] text-muted-foreground">
          Percentuais sobre o preço de venda de {formatCurrency(total, currency)}.
        </p>
      </CardContent>
    </Card>
  );
}

function CostLine({
  label,
  value,
  hint,
  bold = false,
}: {
  label: string;
  value: string;
  hint?: string;
  bold?: boolean;
}) {
  const labelNode = hint ? (
    <Tooltip>
      <TooltipTrigger className="cursor-help text-left underline decoration-dotted underline-offset-4">
        {label}
      </TooltipTrigger>
      <TooltipContent className="max-w-64 text-xs">{hint}</TooltipContent>
    </Tooltip>
  ) : (
    label
  );

  return (
    <div
      className={`flex items-center justify-between text-sm ${
        bold ? "font-semibold" : "text-muted-foreground"
      }`}
    >
      <span>{labelNode}</span>
      <span className="font-mono tabular-nums">{value}</span>
    </div>
  );
}

function PriceSummarySkeleton() {
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

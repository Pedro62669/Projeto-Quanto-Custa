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

import { useQuoteStore, selectResult } from "@/store/useQuoteStore";
import { formatCurrency } from "@/lib/pricing/engine";

/**
 * Painel financeiro.
 *
 * Um número domina a tela (o preço total) e o resto é subordinado a ele. A
 * decomposição do custo é secundária: existe para dar confiança no número
 * principal, não para competir com ele.
 */
export function PriceSummary() {
  const { result, currency, isSyncing, isConfirmed, error, isEngineStale, quantity } =
    useQuoteStore(
      useShallow((s) => ({
        result: selectResult(s),
        currency: s.currency,
        isSyncing: s.isSyncing,
        isConfirmed: s.confirmed !== null,
        error: s.error,
        isEngineStale: s.isEngineStale,
        quantity: s.spec.quantity,
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
          hint={`${result.effective_margin_percent}% de margem real`}
          emphasis={result.profit_amount > 0 ? "positive" : "negative"}
        />
      </div>

      {/* ── Composição do custo ──────────────────────────────────────────── */}
      <Card>
        <CardContent className="space-y-2.5 p-4">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Composição por unidade
          </h3>

          <CostLine label="Matéria-prima" value={money(result.material_cost, 4)} />
          <CostLine label="Mão de obra" value={money(result.labor_cost, 4)} />
          <CostLine label="Hora-máquina" value={money(result.machine_cost, 4)} />
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

          <CostLine
            label="Plano de corte"
            value={`${result.blank_width_mm} × ${result.blank_height_mm} mm`}
            hint="Retângulo de material que cada peça consome, já com abas de colagem e fechamento."
          />

          {/* Só aparece nos modelos com tampa separada — nos demais, os
              campos vêm nulos da API e a linha não faz sentido. */}
          {result.lid_width_mm !== null && (
            <CostLine
              label="Tampa (L × P × A)"
              value={`${result.lid_width_mm} × ${result.lid_depth_mm} × ${result.lid_height_mm} mm`}
              hint="Medidas externas da tampa. Já incluem a folga de encaixe e a espessura do material, para que ela deslize sobre a base."
            />
          )}
          <CostLine
            label="Área por unidade"
            value={`${result.area_m2_per_unit.toFixed(4)} m²`}
          />
          <CostLine
            label="Área total"
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
  emphasis,
}: {
  label: string;
  value: string;
  hint?: string;
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
        {hint && <p className="text-[11px] text-muted-foreground">{hint}</p>}
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

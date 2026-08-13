"use client";

import { useState } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Skeleton } from "@/components/ui/skeleton";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import { ErrorState } from "@/components/data/states";

import { useApi, mensagemDeErro } from "@/hooks/useApi";
import { api, type CostSettingVersion } from "@/lib/api";
import { calculateCompanyHour, formatCurrency } from "@/lib/pricing/engine";
import type { CompanyHourParams, EfficiencyPercent } from "@/lib/pricing/types";

/**
 * Hora-empresa — quanto custa um minuto desta empresa.
 *
 * O diferencial do produto. Em vez de perguntar "quanto vale sua hora?", o
 * sistema soma o que a empresa paga por mês (despesas fixas + depreciação do
 * parque) e divide pelas horas que DE FATO produzem. O resultado costuma
 * surpreender: quem cobrava R$ 25/h descobre que a hora dele custa R$ 41.
 *
 * A conta roda NO NAVEGADOR, e é confiável porque `calculateCompanyHour()` é
 * gêmeo verificado do `CompanyHourCalculator` do PHP — a suíte de paridade
 * compara os dois campo a campo a cada mudança. É o que permite arrastar a
 * eficiência e ver o minuto mudar na hora, sem uma ida ao servidor por clique.
 */
export function AbaHoraEmpresa() {
  const fixos = useApi("hora:fixos", () => api.costs.fixedWithTotal());
  const equipamentos = useApi("hora:equipamentos", () => api.costs.equipment.list());
  const vigente = useApi("hora:parametros", () => api.costs.current());

  if (fixos.error || equipamentos.error || vigente.error) {
    return (
      <ErrorState
        message={fixos.error ?? equipamentos.error ?? vigente.error ?? ""}
        onRetry={() => {
          fixos.refetch();
          equipamentos.refetch();
          vigente.refetch();
        }}
      />
    );
  }

  if (!fixos.data || !equipamentos.data || !vigente.data) {
    return <Skeleton className="h-96 w-full" />;
  }

  return (
    <Editor
      key={`hora:${vigente.data.id}`}
      despesasAtivas={fixos.data.data.filter((c) => c.is_active).map((c) => c.monthly_amount)}
      equipamentos={equipamentos.data}
      vigente={vigente.data}
      onPublicado={vigente.refetch}
    />
  );
}

function Editor({
  despesasAtivas,
  equipamentos,
  vigente,
  onPublicado,
}: {
  despesasAtivas: number[];
  equipamentos: { purchase_value: number; useful_life_months: number }[];
  vigente: CostSettingVersion;
  onPublicado: () => void;
}) {
  const [params, setParams] = useState<CompanyHourParams>({
    hours_per_day: Number(vigente.company_hours_per_day ?? 8),
    days_per_month: Number(vigente.company_days_per_month ?? 22),
    efficiency_percent: (Number(vigente.company_efficiency_percent) ||
      85) as EfficiencyPercent,
    include_depreciation: vigente.company_includes_depreciation !== false,
    monthly_production_volume: Number(vigente.monthly_production_volume ?? 1000),
  });

  const [ligado, setLigado] = useState(vigente.use_company_hour === true);
  const [salvando, setSalvando] = useState(false);

  const define = <K extends keyof CompanyHourParams>(
    campo: K,
    valor: CompanyHourParams[K],
  ) => setParams((atual) => ({ ...atual, [campo]: valor }));

  const resultado = calculateCompanyHour({
    fixedCostAmounts: despesasAtivas,
    equipment: equipamentos,
    params,
  });

  async function publicar() {
    setSalvando(true);

    try {
      /*
       * Republica os parâmetros gerais JUNTO com o regime.
       *
       * O endpoint cria uma versão nova e exige os campos obrigatórios; mandar
       * só o bloco da hora-empresa zeraria tarifa de energia e hora-máquina, e
       * o preço de toda a empresa mudaria de um jeito que ninguém pediu.
       */
      await api.costs.publish({
        energy_tariff_per_kwh: vigente.energy_tariff_per_kwh,
        machine_hour_rate: vigente.machine_hour_rate,
        machine_power_kw: vigente.machine_power_kw,
        labor_hour_rate: vigente.labor_hour_rate,
        tax_percent: vigente.tax_percent,
        default_profit_margin_percent: vigente.default_profit_margin_percent,

        use_company_hour: ligado,
        company_hours_per_day: params.hours_per_day,
        company_days_per_month: params.days_per_month,
        company_efficiency_percent: params.efficiency_percent,
        company_includes_depreciation: params.include_depreciation,
        monthly_production_volume: params.monthly_production_volume,
      });

      toast.success(
        ligado ? "Hora-empresa ligada" : "Hora-empresa desligada",
        { description: "Os próximos orçamentos usam esta versão." },
      );

      onPublicado();
    } catch (erro) {
      toast.error(mensagemDeErro(erro));
    } finally {
      setSalvando(false);
    }
  }

  return (
    <div className="space-y-4">
      {/* ── O regime ─────────────────────────────────────────────────────── */}
      <Card>
        <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
          <div className="min-w-0">
            <Label htmlFor="modo-hora" className="text-sm font-medium">
              Usar a hora-empresa no cálculo
            </Label>
            <p className="mt-0.5 text-xs text-muted-foreground">
              Ligado, o minuto real substitui a mão de obra por hora e zera o rateio
              percentual — as duas coisas cobram as mesmas despesas, e somar as duas
              cobraria o aluguel duas vezes.
            </p>
          </div>

          <Switch id="modo-hora" checked={ligado} onCheckedChange={setLigado} />
        </CardContent>
      </Card>

      {/* ── A jornada ────────────────────────────────────────────────────── */}
      <Card>
        <CardContent className="space-y-4 p-4">
          <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Jornada de trabalho
          </h3>

          <div className="grid gap-3 sm:grid-cols-3">
            <Campo
              id="horas-dia"
              rotulo="Horas por dia"
              valor={params.hours_per_day}
              onChange={(v) => define("hours_per_day", v)}
              min={0.5}
              max={24}
            />
            <Campo
              id="dias-mes"
              rotulo="Dias por mês"
              valor={params.days_per_month}
              onChange={(v) => define("days_per_month", v)}
              min={1}
              max={31}
            />
            <Campo
              id="volume"
              rotulo="Peças por mês"
              valor={params.monthly_production_volume}
              onChange={(v) => define("monthly_production_volume", v)}
              min={1}
            />
          </div>

          <div className="space-y-2">
            <Label className="text-xs">Eficiência</Label>

            {/*
              Nem toda hora na oficina é hora produtiva: há acerto de máquina,
              café, telefone e o pedido que voltou. Assumir 100% é o erro que faz
              o custo do minuto sair barato demais.
            */}
            <ToggleGroup
              type="single"
              size="sm"
              variant="outline"
              value={String(params.efficiency_percent)}
              onValueChange={(v) =>
                v && define("efficiency_percent", Number(v) as EfficiencyPercent)
              }
            >
              <ToggleGroupItem value="100" className="flex-1 text-xs">
                100% · otimista
              </ToggleGroupItem>
              <ToggleGroupItem value="85" className="flex-1 text-xs">
                85% · realista
              </ToggleGroupItem>
              <ToggleGroupItem value="75" className="flex-1 text-xs">
                75% · conservador
              </ToggleGroupItem>
            </ToggleGroup>
          </div>

          <div className="flex items-center justify-between rounded-lg border p-3">
            <div>
              <Label htmlFor="incluir-deprec" className="text-sm">
                Incluir a depreciação do parque
              </Label>
              <p className="text-xs text-muted-foreground">
                A máquina se gasta a cada caixa. Sem isto, ela não volta em preço
                nenhum.
              </p>
            </div>
            <Switch
              id="incluir-deprec"
              checked={params.include_depreciation}
              onCheckedChange={(v) => define("include_depreciation", v)}
            />
          </div>
        </CardContent>
      </Card>

      {/*
        Base zerada: o minuto sai R$ 0,00 e a tela precisa dizer POR QUÊ.
        Sem este aviso, quem chega aqui primeiro conclui que a conta não
        funciona — quando o que falta é o que alimenta a conta, nas abas ao
        lado.
      */}
      {resultado.cost_base.total === 0 && (
        <p className="rounded-md bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-500">
          Nenhuma despesa fixa nem equipamento cadastrado — por isso o minuto sai
          zerado. Preencha as abas <strong className="font-medium">Custos fixos</strong>{" "}
          e <strong className="font-medium">Equipamentos</strong>: é delas que sai o
          que a empresa paga por mês.
        </p>
      )}

      {/* ── O resultado ──────────────────────────────────────────────────── */}
      <Card className="border-primary/20 bg-primary/5">
        <CardContent className="space-y-4 p-4">
          <div className="flex flex-wrap items-end justify-between gap-4">
            <div>
              <p className="text-xs uppercase tracking-wide text-muted-foreground">
                Custo do minuto
              </p>
              <p className="font-mono text-3xl font-bold tabular-nums">
                {formatCurrency(resultado.active_scenario.minute_cost, "BRL", 4)}
              </p>
              <p className="text-sm text-muted-foreground">
                {formatCurrency(resultado.active_scenario.hour_cost)} por hora ·{" "}
                {resultado.active_scenario.productive_hours.toFixed(1)} h produtivas
                no mês
              </p>
            </div>

            <div className="text-right text-xs text-muted-foreground">
              <p>
                Base:{" "}
                <span className="font-mono tabular-nums">
                  {formatCurrency(resultado.cost_base.total)}
                </span>
              </p>
              <p>
                fixos {formatCurrency(resultado.cost_base.fixed_costs)} + depreciação{" "}
                {formatCurrency(resultado.cost_base.depreciation)}
              </p>
            </div>
          </div>

          {/* Os três cenários lado a lado: é a comparação que mostra o quanto a
              eficiência assumida muda o preço — e ela costuma mudar mais que a
              margem de lucro que o usuário passa a tarde ajustando. */}
          <div className="grid gap-2 sm:grid-cols-3">
            {resultado.comparison.map((cenario) => {
              const ativo = cenario.efficiency_percent === params.efficiency_percent;

              return (
                <div
                  key={cenario.efficiency_percent}
                  className={`rounded-lg border p-3 ${
                    ativo ? "border-primary bg-background" : "bg-background/50"
                  }`}
                >
                  <p className="text-[11px] text-muted-foreground">{cenario.label}</p>
                  <p className="font-mono text-sm font-semibold tabular-nums">
                    {formatCurrency(cenario.minute_cost, "BRL", 4)}/min
                  </p>
                  <p className="font-mono text-[11px] text-muted-foreground tabular-nums">
                    {formatCurrency(cenario.hour_cost)}/h
                  </p>
                </div>
              );
            })}
          </div>

          {resultado.depreciation_per_unit > 0 && (
            <p className="text-xs text-muted-foreground">
              Cada peça carrega{" "}
              <span className="font-mono tabular-nums">
                {formatCurrency(resultado.depreciation_per_unit, "BRL", 4)}
              </span>{" "}
              de desgaste do parque, no volume informado.
            </p>
          )}
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-xs text-muted-foreground">
          Os números acima são calculados no navegador pelo mesmo motor do servidor
          — a suíte de paridade compara os dois a cada mudança.
        </p>

        <Button onClick={publicar} disabled={salvando}>
          {salvando && <Loader2 className="size-4 animate-spin" />}
          Publicar nova versão
        </Button>
      </div>
    </div>
  );
}

function Campo({
  id,
  rotulo,
  valor,
  onChange,
  min,
  max,
}: {
  id: string;
  rotulo: string;
  valor: number;
  onChange: (valor: number) => void;
  min?: number;
  max?: number;
}) {
  return (
    <div className="space-y-1">
      <Label htmlFor={id} className="text-xs">
        {rotulo}
      </Label>
      <Input
        id={id}
        type="number"
        min={min}
        max={max}
        step="any"
        value={valor}
        onChange={(e) => onChange(Number(e.target.value) || 0)}
        className="font-mono tabular-nums"
      />
    </div>
  );
}

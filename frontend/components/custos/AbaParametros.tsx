"use client";

import { useState } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { FormError, NumberField } from "@/components/form/Field";
import type { FieldErrors } from "@/components/form/Field";
import { ErrorState } from "@/components/data/states";

import { useApi, mensagemDeErro } from "@/hooks/useApi";
import { api, ApiError, type CostSettingVersion } from "@/lib/api";

interface Formulario {
  energy_tariff_per_kwh: number | null;
  machine_hour_rate: number | null;
  machine_power_kw: number | null;
  labor_hour_rate: number | null;
  overhead_percent: number | null;
  tax_percent: number | null;
  default_profit_margin_percent: number | null;
}

/**
 * Parâmetros de custo — a versão vigente.
 *
 * Publicar não EDITA: cria uma versão nova, com data de vigência. É o que
 * mantém auditável um orçamento fechado meses atrás — ele aponta para a versão
 * que valia naquele dia, e nenhuma correção de tarifa muda o preço que o
 * cliente aprovou.
 */
export function AbaParametros() {
  const atual = useApi("custos:parametros", () => api.costs.current());

  if (atual.error) return <ErrorState message={atual.error} onRetry={atual.refetch} />;

  if (!atual.data) return <Skeleton className="h-96 w-full" />;

  return (
    <FormularioDeParametros
      key={`parametros:${atual.data.id}`}
      inicial={atual.data}
      onPublicado={atual.refetch}
    />
  );
}

function FormularioDeParametros({
  inicial,
  onPublicado,
}: {
  inicial: CostSettingVersion;
  onPublicado: () => void;
}) {
  const [form, setForm] = useState<Formulario>({
    energy_tariff_per_kwh: inicial.energy_tariff_per_kwh,
    machine_hour_rate: inicial.machine_hour_rate,
    machine_power_kw: inicial.machine_power_kw,
    labor_hour_rate: inicial.labor_hour_rate,
    overhead_percent: inicial.overhead_percent,
    tax_percent: inicial.tax_percent,
    default_profit_margin_percent: inicial.default_profit_margin_percent,
  });

  const [errors, setErrors] = useState<FieldErrors>({});
  const [erroGeral, setErroGeral] = useState<string | null>(null);
  const [salvando, setSalvando] = useState(false);

  const define = (campo: keyof Formulario, valor: number | null) =>
    setForm((atual) => ({ ...atual, [campo]: valor }));

  /*
   * O modo hora-empresa continua ligado onde estava.
   *
   * Ele é publicado junto, mas quem o configura é a aba da hora-empresa —
   * mandar `use_company_hour: false` daqui por omissão DESLIGARIA o regime sem
   * ninguém pedir, e o preço de toda a empresa mudaria por causa de um salvar
   * numa tela vizinha.
   */
  const modoVigente = {
    use_company_hour: inicial.use_company_hour ?? false,
  };

  async function publicar(evento: React.FormEvent) {
    evento.preventDefault();

    setSalvando(true);
    setErrors({});
    setErroGeral(null);

    try {
      await api.costs.publish({ ...form, ...modoVigente });

      toast.success("Nova versão publicada", {
        description: "Os próximos orçamentos usam estes valores.",
      });

      onPublicado();
    } catch (erro) {
      if (erro instanceof ApiError && Object.keys(erro.errors).length > 0) {
        setErrors(erro.errors);
      } else {
        setErroGeral(mensagemDeErro(erro));
      }
    } finally {
      setSalvando(false);
    }
  }

  return (
    <form onSubmit={publicar} className="space-y-4">
      <FormError message={erroGeral} />

      <Card>
        <CardContent className="space-y-3 p-4">
          <Titulo>Produção</Titulo>

          <div className="grid gap-3 sm:grid-cols-2">
            <NumberField
              label="Mão de obra por hora"
              name="labor_hour_rate"
              required
              value={form.labor_hour_rate}
              onChange={(v) => define("labor_hour_rate", v)}
              errors={errors}
              min={0}
              hint={
                inicial.use_company_hour
                  ? "Ignorado: o modo hora-empresa está ligado e o minuto vem das despesas reais."
                  : "O quanto custa uma hora de quem monta a caixa, com encargos."
              }
            />
            <NumberField
              label="Hora-máquina"
              name="machine_hour_rate"
              required
              value={form.machine_hour_rate}
              onChange={(v) => define("machine_hour_rate", v)}
              errors={errors}
              min={0}
              hint="Manutenção e uso do equipamento por hora."
            />
            <NumberField
              label="Potência da máquina"
              suffix="kW"
              name="machine_power_kw"
              required
              value={form.machine_power_kw}
              onChange={(v) => define("machine_power_kw", v)}
              errors={errors}
              min={0}
            />
            <NumberField
              label="Tarifa de energia"
              suffix="R$/kWh"
              name="energy_tariff_per_kwh"
              required
              value={form.energy_tariff_per_kwh}
              onChange={(v) => define("energy_tariff_per_kwh", v)}
              errors={errors}
              min={0}
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="space-y-3 p-4">
          <Titulo>Rateio, imposto e lucro</Titulo>

          <div className="grid gap-3 sm:grid-cols-3">
            <NumberField
              label="Custos indiretos"
              suffix="%"
              name="overhead_percent"
              value={form.overhead_percent}
              onChange={(v) => define("overhead_percent", v)}
              errors={errors}
              min={0}
              hint={
                inicial.use_company_hour
                  ? "Zerado no modo hora-empresa: as despesas fixas já entram pelo minuto."
                  : "Percentual sobre o custo direto."
              }
            />
            <NumberField
              label="Imposto"
              suffix="%"
              name="tax_percent"
              value={form.tax_percent}
              onChange={(v) => define("tax_percent", v)}
              errors={errors}
              min={0}
              max={99.99}
            />
            <NumberField
              label="Lucro padrão"
              suffix="%"
              name="default_profit_margin_percent"
              value={form.default_profit_margin_percent}
              onChange={(v) => define("default_profit_margin_percent", v)}
              errors={errors}
              min={0}
              hint="Sugestão inicial da calculadora."
            />
          </div>
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-xs text-muted-foreground">
          Publicar cria a versão <strong className="font-medium">#{inicial.id + 1}</strong>.
          A anterior continua valendo para os orçamentos que a usaram.
        </p>

        <Button type="submit" disabled={salvando}>
          {salvando && <Loader2 className="size-4 animate-spin" />}
          Publicar nova versão
        </Button>
      </div>
    </form>
  );
}

function Titulo({ children }: { children: React.ReactNode }) {
  return (
    <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
      {children}
    </h2>
  );
}

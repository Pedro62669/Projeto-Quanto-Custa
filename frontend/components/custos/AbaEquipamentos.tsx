"use client";

import { useState } from "react";
import { Loader2, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { DataTable, EmptyState, type Column } from "@/components/data/DataTable";

import { useApi, mensagemDeErro } from "@/hooks/useApi";
import { api, type Equipment } from "@/lib/api";
import { formatCurrency } from "@/lib/pricing/engine";
import { useDebounce } from "@/hooks/useDebounce";

/**
 * Parque de máquinas.
 *
 * A guilhotina, a laminadora, o compressor. O que interessa ao preço não é o
 * valor da máquina: é a DEPRECIAÇÃO — quanto dela se consome por mês —, porque
 * é isso que precisa voltar em cada caixa vendida para a empresa conseguir
 * comprar a próxima quando esta acabar.
 */
export function AbaEquipamentos() {
  const lista = useApi("custos:equipamentos", () => api.costs.equipment.list());

  const [nome, setNome] = useState("");
  const [valor, setValor] = useState("");
  const [vida, setVida] = useState("60");
  const [salvando, setSalvando] = useState(false);

  // Volume mensal para o cálculo de impacto. Em debounce porque cada mudança
  // consulta o servidor.
  const [volume, setVolume] = useState("1000");
  const volumeConsultado = useDebounce(volume, 500);

  const impacto = useApi(`custos:depreciacao:${volumeConsultado}`, () =>
    api.costs.depreciationImpact(Math.max(1, Number(volumeConsultado) || 1)),
  );

  async function adicionar(evento: React.FormEvent) {
    evento.preventDefault();

    setSalvando(true);

    try {
      await api.costs.equipment.create({
        name: nome,
        purchase_value: Number(valor),
        useful_life_months: Number(vida),
      });

      setNome("");
      setValor("");
      lista.refetch();
      impacto.refetch();
    } catch (erro) {
      toast.error(mensagemDeErro(erro));
    } finally {
      setSalvando(false);
    }
  }

  async function remover(equipamento: Equipment) {
    try {
      await api.costs.equipment.remove(equipamento.id);
      toast.success("Equipamento removido");
      lista.refetch();
      impacto.refetch();
    } catch (erro) {
      toast.error(mensagemDeErro(erro));
    }
  }

  const colunas: Column<Equipment>[] = [
    { header: "Equipamento", render: (e) => <span className="font-medium">{e.name}</span> },
    {
      header: "Valor",
      className: "text-right",
      render: (e) => (
        <span className="font-mono tabular-nums">{formatCurrency(e.purchase_value)}</span>
      ),
    },
    {
      header: "Vida útil",
      className: "text-right",
      render: (e) => (
        <span className="font-mono text-xs tabular-nums">
          {e.useful_life_months} meses
        </span>
      ),
    },
    {
      header: "Depreciação/mês",
      className: "text-right",
      render: (e) => (
        <span className="font-mono tabular-nums">
          {formatCurrency(e.monthly_depreciation)}
        </span>
      ),
    },
    {
      header: "",
      className: "w-12 text-right",
      render: (e) => (
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={`Remover ${e.name}`}
          onClick={() => remover(e)}
          className="text-muted-foreground hover:text-destructive"
        >
          <Trash2 />
        </Button>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <Card>
        <CardContent className="p-4">
          <form onSubmit={adicionar} className="flex flex-wrap items-end gap-2">
            <div className="min-w-0 flex-1 space-y-1">
              <label htmlFor="equip-nome" className="text-xs text-muted-foreground">
                Equipamento
              </label>
              <Input
                id="equip-nome"
                value={nome}
                onChange={(e) => setNome(e.target.value)}
                placeholder="Guilhotina 800mm"
                required
              />
            </div>

            <div className="w-36 space-y-1">
              <label htmlFor="equip-valor" className="text-xs text-muted-foreground">
                Valor de compra
              </label>
              <Input
                id="equip-valor"
                type="number"
                min={0.01}
                step="0.01"
                value={valor}
                onChange={(e) => setValor(e.target.value)}
                className="font-mono tabular-nums"
                required
              />
            </div>

            <div className="w-28 space-y-1">
              <label htmlFor="equip-vida" className="text-xs text-muted-foreground">
                Vida (meses)
              </label>
              <Input
                id="equip-vida"
                type="number"
                min={1}
                max={600}
                value={vida}
                onChange={(e) => setVida(e.target.value)}
                className="font-mono tabular-nums"
                required
              />
            </div>

            <Button type="submit" disabled={salvando || !nome || !valor}>
              {salvando ? <Loader2 className="size-4 animate-spin" /> : <Plus className="size-4" />}
              Adicionar
            </Button>
          </form>
        </CardContent>
      </Card>

      <DataTable
        columns={colunas}
        rows={lista.data ?? []}
        loading={lista.loading}
        error={lista.error}
        onRetry={lista.refetch}
        rowKey={(e) => e.id}
        empty={
          <EmptyState
            title="Nenhum equipamento cadastrado"
            description="Sem o parque, a hora-empresa cobra apenas as despesas fixas — e a máquina que se desgasta a cada caixa não volta em preço nenhum."
          />
        }
      />

      {/* ── Quanto a máquina pesa em cada peça ──────────────────────────── */}
      <Card>
        <CardContent className="space-y-3 p-4">
          <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Impacto por peça
          </h3>

          <div className="flex flex-wrap items-end gap-3">
            <div className="w-40 space-y-1">
              <label htmlFor="volume" className="text-xs text-muted-foreground">
                Produção mensal (peças)
              </label>
              <Input
                id="volume"
                type="number"
                min={1}
                value={volume}
                onChange={(e) => setVolume(e.target.value)}
                className="font-mono tabular-nums"
              />
            </div>

            <div className="flex-1 rounded-lg bg-muted/50 px-4 py-3">
              {impacto.data ? (
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                  <span className="text-sm text-muted-foreground">
                    Depreciação embutida em cada peça
                  </span>
                  <span className="font-mono text-lg font-semibold tabular-nums">
                    {formatCurrency(impacto.data.cost_per_unit ?? 0, "BRL", 4)}
                  </span>
                </div>
              ) : (
                <span className="text-sm text-muted-foreground">Calculando…</span>
              )}
            </div>
          </div>

          <p className="text-xs text-muted-foreground">
            Quanto menos se produz, mais pesada fica a máquina em cada peça — é a
            mesma depreciação dividida por menos caixas. Este número entra no preço
            pela hora-empresa, não como linha separada.
          </p>
        </CardContent>
      </Card>
    </div>
  );
}

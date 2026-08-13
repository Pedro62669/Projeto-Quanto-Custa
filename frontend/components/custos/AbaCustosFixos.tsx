"use client";

import { useState } from "react";
import { Loader2, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { DataTable, EmptyState, type Column } from "@/components/data/DataTable";

import { useApi, mensagemDeErro } from "@/hooks/useApi";
import { api, type FixedCost } from "@/lib/api";
import { formatCurrency } from "@/lib/pricing/engine";

/**
 * Despesas fixas mensais.
 *
 * Aluguel, contador, internet, salário do administrativo — tudo que a empresa
 * paga exista ou não pedido no mês. É a base da hora-empresa: sem esta lista, o
 * custo do minuto seria um chute, e a caixa sairia por um preço que não cobre o
 * aluguel do galpão onde ela foi feita.
 *
 * O cadastro é INLINE, sem diálogo: são linhas de duas informações, e abrir uma
 * janela para digitar "Aluguel / 2400" é atrito sem ganho.
 */
export function AbaCustosFixos() {
  const lista = useApi("custos:fixos", () => api.costs.fixedWithTotal());

  const [nome, setNome] = useState("");
  const [valor, setValor] = useState("");
  const [salvando, setSalvando] = useState(false);

  async function adicionar(evento: React.FormEvent) {
    evento.preventDefault();

    setSalvando(true);

    try {
      await api.costs.fixed.create({
        name: nome,
        monthly_amount: Number(valor),
        is_active: true,
      });

      setNome("");
      setValor("");
      lista.refetch();
    } catch (erro) {
      toast.error(mensagemDeErro(erro));
    } finally {
      setSalvando(false);
    }
  }

  async function alternar(custo: FixedCost) {
    try {
      await api.costs.fixed.update(custo.id, {
        name: custo.name,
        monthly_amount: custo.monthly_amount,
        is_active: !custo.is_active,
      });

      lista.refetch();
    } catch (erro) {
      toast.error(mensagemDeErro(erro));
    }
  }

  async function remover(custo: FixedCost) {
    try {
      await api.costs.fixed.remove(custo.id);
      toast.success("Despesa removida");
      lista.refetch();
    } catch (erro) {
      toast.error(mensagemDeErro(erro));
    }
  }

  const colunas: Column<FixedCost>[] = [
    {
      header: "Despesa",
      render: (c) => (
        <span className={c.is_active ? "font-medium" : "text-muted-foreground line-through"}>
          {c.name}
        </span>
      ),
    },
    {
      header: "Por mês",
      className: "text-right",
      render: (c) => (
        <span className="font-mono tabular-nums">
          {formatCurrency(c.monthly_amount)}
        </span>
      ),
    },
    {
      header: "Entra na conta",
      className: "w-32 text-right",
      render: (c) => (
        <div className="flex items-center justify-end gap-2">
          {/* Desativar em vez de apagar: uma despesa suspensa por dois meses
              volta, e recadastrá-la é perder o histórico. */}
          <Switch
            checked={c.is_active}
            onCheckedChange={() => alternar(c)}
            aria-label={`${c.is_active ? "Desativar" : "Ativar"} ${c.name}`}
          />
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={`Remover ${c.name}`}
            onClick={() => remover(c)}
            className="text-muted-foreground hover:text-destructive"
          >
            <Trash2 />
          </Button>
        </div>
      ),
    },
  ];

  const total = lista.data?.meta.monthly_total ?? 0;

  return (
    <div className="space-y-4">
      <Card>
        <CardContent className="p-4">
          <form onSubmit={adicionar} className="flex flex-wrap items-end gap-2">
            <div className="min-w-0 flex-1 space-y-1">
              <label htmlFor="custo-nome" className="text-xs text-muted-foreground">
                Despesa
              </label>
              <Input
                id="custo-nome"
                value={nome}
                onChange={(e) => setNome(e.target.value)}
                placeholder="Aluguel do galpão"
                required
              />
            </div>

            <div className="w-36 space-y-1">
              <label htmlFor="custo-valor" className="text-xs text-muted-foreground">
                Valor mensal
              </label>
              <Input
                id="custo-valor"
                type="number"
                min={0}
                step="0.01"
                value={valor}
                onChange={(e) => setValor(e.target.value)}
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
        rows={lista.data?.data ?? []}
        loading={lista.loading}
        error={lista.error}
        onRetry={lista.refetch}
        rowKey={(c) => c.id}
        empty={
          <EmptyState
            title="Nenhuma despesa fixa cadastrada"
            description="Aluguel, contador, internet, salários administrativos — o que a empresa paga todo mês, com ou sem pedido."
          />
        }
      />

      {/* O total vem do SERVIDOR, com o mesmo filtro que a hora-empresa aplica.
          Somar aqui abriria a porta para dois números diferentes na interface. */}
      <div className="flex items-center justify-between rounded-lg bg-muted/50 px-4 py-3">
        <span className="text-sm text-muted-foreground">Custo fixo mensal ativo</span>
        <span className="font-mono text-lg font-semibold tabular-nums">
          {formatCurrency(total)}
        </span>
      </div>
    </div>
  );
}

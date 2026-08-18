"use client";

import { useState } from "react";
import { Loader2, ShoppingCart } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";

import { SeletorDeCliente } from "@/components/cadastro/SeletorDeCliente";
import { api, ApiError, type Product } from "@/lib/api";
import { formatCurrency } from "@/lib/pricing/engine";

/**
 * Vender do catálogo.
 *
 * O elo que faltava entre Produtos e o caixa. Até aqui vender era duas ações
 * separadas que ninguém garantia acontecerem juntas: lançar a entrada no
 * financeiro e editar o estoque no cadastro. Uma transação de banco só, agora —
 * e a categoria `ProductSale`, que existia no enum desde a Fase 4 sem nunca ter
 * sido usada, finalmente tem quem a escreva.
 *
 * O cliente é OPCIONAL: venda de balcão fecha sem nome, e exigir um travaria o
 * caso mais rápido. Quando vem, a venda aparece na ficha dele ao lado dos
 * orçamentos.
 */
export function DialogoDeVenda({
  produto,
  onVendido,
}: {
  produto: Product;
  onVendido: () => void;
}) {
  const [aberto, setAberto] = useState(false);
  const [salvando, setSalvando] = useState(false);
  const [erros, setErros] = useState<Record<string, string[]>>({});

  const [quantidade, setQuantidade] = useState(1);
  const [preco, setPreco] = useState<number | null>(null);
  const [parcelas, setParcelas] = useState(1);
  const [cliente, setCliente] = useState<{ id: number | null; nome: string }>({
    id: null,
    nome: "",
  });

  const semEstoque = (produto.stock_quantity ?? 0) <= 0;

  // O preço do cadastro é o padrão; o campo cobre o desconto de balcão sem
  // obrigar a editar o produto — editar mudaria todas as vendas seguintes.
  const unitario = preco ?? produto.sale_price ?? 0;
  const total = unitario * quantidade;

  async function vender(evento: React.FormEvent) {
    evento.preventDefault();
    setSalvando(true);
    setErros({});

    try {
      await api.products.sell(produto.id, {
        quantity: quantidade,
        unit_price: preco ?? undefined,
        client_id: cliente.id ?? undefined,
        installments: parcelas,
      });

      toast.success(`Venda de ${quantidade}× ${produto.name} lançada`, {
        description: "Entrada no caixa e estoque baixado.",
      });

      setAberto(false);
      setQuantidade(1);
      setPreco(null);
      setParcelas(1);
      setCliente({ id: null, nome: "" });
      onVendido();
    } catch (erro) {
      if (erro instanceof ApiError) {
        setErros(erro.errors);
        if (Object.keys(erro.errors).length === 0) toast.error(erro.message);
      } else {
        toast.error("Não foi possível lançar a venda.");
      }
    } finally {
      setSalvando(false);
    }
  }

  return (
    <Dialog open={aberto} onOpenChange={setAberto}>
      <DialogTrigger asChild>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={`Vender ${produto.name}`}
          disabled={semEstoque}
          // Desabilitado diz por quê: sem isso a pessoa conclui que o sistema
          // não vende esse produto, quando o que falta é repor.
          title={semEstoque ? "Sem estoque para vender." : undefined}
        >
          <ShoppingCart />
        </Button>
      </DialogTrigger>

      <DialogContent className="sm:max-w-md">
        <form onSubmit={vender}>
          <DialogHeader>
            <DialogTitle>Vender {produto.name}</DialogTitle>
            <DialogDescription>
              Lança a entrada no caixa e baixa o estoque. Há{" "}
              {produto.stock_quantity} em estoque.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-4">
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label htmlFor="venda-quantidade">Quantidade</Label>
                <Input
                  id="venda-quantidade"
                  type="number"
                  min={1}
                  max={produto.stock_quantity ?? undefined}
                  value={quantidade}
                  onChange={(e) => setQuantidade(Number(e.target.value))}
                  aria-invalid={Boolean(erros.quantity?.length)}
                />
                {erros.quantity?.map((m) => (
                  <p key={m} className="text-xs text-destructive">
                    {m}
                  </p>
                ))}
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="venda-preco">Preço unitário</Label>
                <Input
                  id="venda-preco"
                  type="number"
                  min={0}
                  step="0.01"
                  placeholder={String(produto.sale_price ?? 0)}
                  value={preco ?? ""}
                  onChange={(e) =>
                    setPreco(e.target.value === "" ? null : Number(e.target.value))
                  }
                />
              </div>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="venda-parcelas">Parcelas</Label>
              <Input
                id="venda-parcelas"
                type="number"
                min={1}
                max={60}
                value={parcelas}
                onChange={(e) => setParcelas(Number(e.target.value))}
              />
            </div>

            <SeletorDeCliente
              clienteId={cliente.id}
              nome={cliente.nome}
              onChange={({ clienteId, nome }) =>
                setCliente({ id: clienteId, nome })
              }
            />

            <p className="text-xs text-muted-foreground">
              O cliente é opcional — venda de balcão fecha sem nome. Quando
              informado, a venda entra na ficha dele.
            </p>

            <div className="flex items-center justify-between rounded-md bg-muted px-3 py-2">
              <span className="text-sm text-muted-foreground">Total</span>
              <span className="font-mono text-lg font-semibold tabular-nums">
                {formatCurrency(total)}
              </span>
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => setAberto(false)}>
              Cancelar
            </Button>
            <Button type="submit" disabled={salvando || quantidade < 1}>
              {salvando && <Loader2 className="size-4 animate-spin" />}
              Lançar venda
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

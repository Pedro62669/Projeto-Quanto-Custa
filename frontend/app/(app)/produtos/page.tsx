"use client";

import { CadastroSimples, SeloAtivo } from "@/components/cadastro/CadastroSimples";
import { NumberField, TextField } from "@/components/form/Field";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { api, type Product } from "@/lib/api";
import { formatCurrency } from "@/lib/pricing/engine";

interface Formulario {
  name: string;
  sku: string;
  cost_price: number | null;
  sale_price: number | null;
  stock_quantity: number | null;
  description: string;
  is_active: boolean;
}

/**
 * Produtos de prateleira.
 *
 * O que a empresa vende PRONTO, sem passar pela calculadora: caixinhas de
 * estoque, sacolas, papel avulso. A margem é calculada pelo servidor a partir do
 * custo e do preço — e é ela que denuncia o produto que só dá trabalho.
 */
export default function ProdutosPage() {
  return (
    <CadastroSimples<Product, Formulario>
      titulo="Produtos"
      descricao="O que se vende pronto, sem cálculo de caixa. A margem sai do custo contra o preço de venda."
      substantivo="produto"
      chave="produtos"
      identidade={(p) => p.id}
      rotulo={(p) => p.name}
      vazioDescricao="Itens de prateleira: sacolas, caixinhas padrão, papel avulso — o que sai sem passar pela calculadora."
      colunas={[
        {
          header: "Produto",
          render: (p) => (
            <div className="flex items-center gap-2">
              <div className="min-w-0">
                <p className="truncate font-medium">{p.name}</p>
                <p className="truncate font-mono text-xs text-muted-foreground">
                  {p.sku ?? "sem SKU"}
                </p>
              </div>
              <SeloAtivo ativo={p.is_active} />
            </div>
          ),
        },
        {
          header: "Custo",
          className: "text-right",
          render: (p) => (
            <span className="font-mono text-xs tabular-nums text-muted-foreground">
              {p.cost_price === null ? "—" : formatCurrency(p.cost_price)}
            </span>
          ),
        },
        {
          header: "Venda",
          className: "text-right",
          render: (p) => (
            <span className="font-mono tabular-nums">
              {p.sale_price === null ? "—" : formatCurrency(p.sale_price)}
            </span>
          ),
        },
        {
          header: "Margem",
          className: "text-right",
          render: (p) => (
            // A margem vem do servidor (`marginPercent()`), não é recalculada
            // aqui: uma segunda fórmula divergiria da primeira sem avisar.
            <span
              className={`font-mono text-xs tabular-nums ${
                (p.margin_percent ?? 0) < 15 ? "text-amber-600 dark:text-amber-500" : ""
              }`}
            >
              {p.margin_percent === null || p.margin_percent === undefined
                ? "—"
                : `${p.margin_percent}%`}
            </span>
          ),
        },
        {
          header: "Estoque",
          className: "text-right",
          render: (p) => (
            <span
              className={`font-mono text-xs tabular-nums ${
                (p.stock_quantity ?? 0) <= 0 ? "text-destructive" : ""
              }`}
            >
              {p.stock_quantity ?? "—"}
            </span>
          ),
        },
      ]}
      vazio={{
        name: "",
        sku: "",
        cost_price: null,
        sale_price: null,
        stock_quantity: 0,
        description: "",
        is_active: true,
      }}
      paraFormulario={(p) => ({
        name: p.name,
        sku: p.sku ?? "",
        cost_price: p.cost_price,
        sale_price: p.sale_price,
        stock_quantity: p.stock_quantity,
        description: p.description ?? "",
        is_active: p.is_active,
      })}
      campos={({ form, define, errors }) => (
        <>
          <TextField
            label="Nome"
            name="name"
            required
            value={form.name}
            onChange={(v) => define("name", v)}
            errors={errors}
          />

          <TextField
            label="SKU"
            name="sku"
            value={form.sku}
            onChange={(v) => define("sku", v)}
            errors={errors}
            hint="Código interno, se a empresa usar um."
          />

          <div className="grid grid-cols-3 gap-3">
            <NumberField
              label="Custo"
              name="cost_price"
              value={form.cost_price}
              onChange={(v) => define("cost_price", v)}
              errors={errors}
              min={0}
            />
            <NumberField
              label="Venda"
              name="sale_price"
              value={form.sale_price}
              onChange={(v) => define("sale_price", v)}
              errors={errors}
              min={0}
            />
            <NumberField
              label="Estoque"
              name="stock_quantity"
              value={form.stock_quantity}
              onChange={(v) => define("stock_quantity", v)}
              errors={errors}
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="produto-descricao" className="text-xs">
              Descrição
            </Label>
            <Textarea
              id="produto-descricao"
              rows={3}
              value={form.description}
              onChange={(e) => define("description", e.target.value)}
            />
          </div>

          <div className="flex items-center justify-between rounded-lg border p-3">
            <Label htmlFor="produto-ativo" className="text-sm">
              Produto ativo
            </Label>
            <Switch
              id="produto-ativo"
              checked={form.is_active}
              onCheckedChange={(v) => define("is_active", v)}
            />
          </div>
        </>
      )}
      api={{
        list: api.products.list,
        create: (form) => api.products.create(paraPayload(form)),
        update: (id, form) => api.products.update(id, paraPayload(form)),
        remove: api.products.remove,
      }}
    />
  );
}

function paraPayload(form: Formulario) {
  return {
    ...form,
    sku: form.sku || null,
    description: form.description || null,
  };
}

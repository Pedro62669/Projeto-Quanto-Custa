"use client";

import { useState } from "react";
import Link from "next/link";

import { CadastroSimples, SeloAtivo } from "@/components/cadastro/CadastroSimples";
import { DialogoDeVenda } from "@/components/produtos/DialogoDeVenda";
import { NumberField, TextField } from "@/components/form/Field";
import { Badge } from "@/components/ui/badge";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Textarea } from "@/components/ui/textarea";
import { api, type Product } from "@/lib/api";
import type { Column } from "@/components/data/DataTable";
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

type Aba = "merchandise" | "box";

/**
 * O catálogo — e a ilha que ele deixou de ser.
 *
 * A tela guardava custo, preço, estoque e margem sem relação nenhuma com o
 * resto do sistema: nem com orçamento, nem com cliente, nem com o caixa. Duas
 * ligações a tiraram do isolamento.
 *
 * A CAIXA PRONTA nasce de um orçamento aprovado, com o preço que o motor
 * calculou e o cliente aceitou. Ela responde "quanto custa aquela caixa que
 * fizemos para a joalheria?" sem refazer a conta — e permite vender de
 * prateleira um modelo que já foi produzido uma vez. Por isso não há botão de
 * criar nesta aba: o servidor recusa, porque preço digitado se passando por
 * preço calculado é o que a ligação existe para impedir.
 *
 * A MERCADORIA é o que se compra pronto e se revende: fita, laço, tag, sacola.
 * Preço digitado, sem proposta por trás — e é o que o formulário cria.
 *
 * As duas vendem pelo mesmo caminho, e vender lança no caixa e baixa o estoque
 * numa transação só.
 */
export default function ProdutosPage() {
  const [aba, setAba] = useState<Aba>("merchandise");

  /*
   * Contador de versão na chave da lista.
   *
   * Vender muda o estoque, e a lista precisa refletir isso sem recarregar a
   * página. `useApi` recarrega quando a chave muda — incrementar aqui é o
   * gatilho, e evita inventar uma camada de invalidação para um caso só.
   */
  const [versao, setVersao] = useState(0);

  const caixas = aba === "box";

  const colunas: Column<Product>[] = [
    {
      header: "Produto",
      render: (p) => (
        <div className="flex items-center gap-2">
          <div className="min-w-0">
            <p className="truncate font-medium">{p.name}</p>
            {/* A caixa mostra a proposta de origem; a mercadoria, o SKU. Cada
                uma diz o que a identifica no mundo real. */}
            {p.quote ? (
              <Link
                href={`/orcamentos/${p.quote.id}`}
                className="truncate font-mono text-xs text-muted-foreground hover:underline"
              >
                {p.quote.reference}
              </Link>
            ) : (
              <p className="truncate font-mono text-xs text-muted-foreground">
                {p.sku ?? "sem SKU"}
              </p>
            )}
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
        // A margem vem do servidor (`marginPercent()`), não é recalculada aqui:
        // uma segunda fórmula divergiria da primeira sem avisar.
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
    {
      header: "",
      className: "w-10",
      render: (p) => (
        <DialogoDeVenda produto={p} onVendido={() => setVersao((v) => v + 1)} />
      ),
    },
  ];

  return (
    <CadastroSimples<Product, Formulario>
      // A chave carrega a aba E a versão: sem a aba, trocar de guia mostraria a
      // lista anterior; sem a versão, o estoque ficaria velho depois de vender.
      chave={`produtos:${aba}:${versao}`}
      titulo="Produtos"
      descricao="O catálogo: as caixas que já foram produzidas e as mercadorias de revenda. Vender lança no caixa e baixa o estoque."
      substantivo="mercadoria"
      identidade={(p) => p.id}
      rotulo={(p) => p.name}
      colunas={colunas}
      /*
       * Caixa pronta nasce de orçamento aprovado, nunca do formulário. Oferecer
       * o botão aqui seria oferecer um caminho que o servidor recusa.
       */
      semCriacao={caixas}
      vazioDescricao={
        caixas
          ? "Nenhuma caixa publicada ainda. Aprove um orçamento e use “Publicar no catálogo” na tela dele."
          : "Itens de prateleira: sacolas, fitas, laços, tags — o que sai sem passar pela calculadora."
      }
      antesDaLista={
        // A aba já está na chave da lista, então trocá-la recarrega sozinha.
        <Tabs value={aba} onValueChange={(v) => setAba(v as Aba)}>
          <TabsList>
            <TabsTrigger value="merchandise">Mercadorias</TabsTrigger>
            <TabsTrigger value="box">
              Caixas prontas
              <Badge variant="secondary" className="ml-1.5 text-[10px]">
                do orçamento
              </Badge>
            </TabsTrigger>
          </TabsList>
        </Tabs>
      }
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
        list: (params) => api.products.list({ ...params, kind: aba }),
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

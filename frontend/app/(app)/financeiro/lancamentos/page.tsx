"use client";

import { useState } from "react";
import { ArrowDownLeft, ArrowUpRight, Loader2, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";

import { PageHeader } from "@/components/PageHeader";
import {
  DataTable,
  EmptyState,
  Pagination,
  type Column,
} from "@/components/data/DataTable";
import { FormError, NumberField, TextField } from "@/components/form/Field";
import type { FieldErrors } from "@/components/form/Field";

import { useApi, mensagemDeErro } from "@/hooks/useApi";
import {
  api,
  ApiError,
  type Transaction,
  type TransactionCategoryValue,
  type TransactionPayload,
  type TransactionTypeValue,
} from "@/lib/api";
import { formatCurrency } from "@/lib/pricing/engine";
import { ROTULO_CATEGORIA, formatarData } from "@/lib/rotulos";

const TODOS = "todos";

/**
 * Livro-caixa — os lançamentos.
 *
 * Toda entrada e saída da empresa. As vendas de orçamento NÃO se lançam aqui:
 * elas nascem quando a proposta é aprovada, com as parcelas já montadas — e o
 * servidor recusa a categoria "venda de orçamento" no lançamento manual
 * justamente para o mesmo dinheiro não entrar duas vezes.
 */
export default function LancamentosPage() {
  const [pagina, setPagina] = useState(1);
  const [tipo, setTipo] = useState(TODOS);
  const [formAberto, setFormAberto] = useState(false);

  const lista = useApi(`lancamentos:${pagina}:${tipo}`, () =>
    api.finance.transactions.list({
      page: pagina,
      type: tipo === TODOS ? undefined : tipo,
    }),
  );

  const colunas: Column<Transaction>[] = [
    {
      header: "Lançamento",
      render: (t) => (
        <div className="flex items-center gap-2">
          <span
            className={`flex size-6 shrink-0 items-center justify-center rounded-full ${
              t.type === "entry"
                ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-500"
                : "bg-destructive/10 text-destructive"
            }`}
            aria-hidden
          >
            {t.type === "entry" ? (
              <ArrowDownLeft className="size-3.5" />
            ) : (
              <ArrowUpRight className="size-3.5" />
            )}
          </span>

          <div className="min-w-0">
            <p className="truncate font-medium">{t.description}</p>
            <p className="truncate text-xs text-muted-foreground">
              {ROTULO_CATEGORIA[t.category] ?? t.category}
              {t.client?.name ? ` · ${t.client.name}` : ""}
              {t.supplier?.name ? ` · ${t.supplier.name}` : ""}
            </p>
          </div>
        </div>
      ),
    },
    {
      header: "Data",
      render: (t) => (
        <span className="font-mono text-xs tabular-nums">
          {formatarData(t.transaction_date)}
        </span>
      ),
    },
    {
      header: "Parcelas",
      className: "text-right",
      render: (t) => {
        const total = t.installments?.length ?? 0;
        const quitadas = t.installments?.filter((p) => p.status === "completed").length ?? 0;

        if (total === 0) return <span className="text-xs text-muted-foreground">—</span>;

        return (
          <Badge variant={quitadas === total ? "secondary" : "outline"} className="font-mono text-[10px]">
            {quitadas}/{total}
          </Badge>
        );
      },
    },
    {
      header: "Valor",
      className: "text-right",
      render: (t) => (
        <span
          className={`font-mono font-medium tabular-nums ${
            t.type === "exit" ? "text-destructive" : ""
          }`}
        >
          {t.type === "exit" ? "−" : ""}
          {formatCurrency(t.amount)}
        </span>
      ),
    },
    {
      header: "",
      className: "w-12 text-right",
      render: (t) => (
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={`Excluir ${t.description}`}
          className="text-muted-foreground hover:text-destructive"
          onClick={async () => {
            try {
              await api.finance.transactions.remove(t.id);
              toast.success("Lançamento excluído");
              lista.refetch();
            } catch (erro) {
              toast.error(mensagemDeErro(erro));
            }
          }}
        >
          <Trash2 />
        </Button>
      ),
    },
  ];

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <PageHeader
        title="Lançamentos"
        description="Entradas e saídas da empresa. Venda de orçamento entra sozinha, quando a proposta é aprovada."
        actions={
          <Button onClick={() => setFormAberto(true)}>
            <Plus className="size-4" />
            Novo lançamento
          </Button>
        }
      />

      <Select
        value={tipo}
        onValueChange={(v) => {
          setTipo(v);
          setPagina(1);
        }}
      >
        <SelectTrigger className="w-44" aria-label="Filtrar por tipo">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={TODOS}>Entradas e saídas</SelectItem>
          <SelectItem value="entry">Só entradas</SelectItem>
          <SelectItem value="exit">Só saídas</SelectItem>
        </SelectContent>
      </Select>

      <DataTable
        columns={colunas}
        rows={lista.data?.items ?? []}
        loading={lista.loading}
        error={lista.error}
        onRetry={lista.refetch}
        rowKey={(t) => t.id}
        empty={
          <EmptyState
            title="Nenhum lançamento"
            description="Compras de material, contas do mês, vendas de balcão — tudo que passa pelo caixa e não é orçamento aprovado."
          />
        }
      />

      <Pagination page={lista.data} onChange={setPagina} />

      {formAberto && (
        <NovoLancamento
          aberto={formAberto}
          onOpenChange={setFormAberto}
          onSalvo={lista.refetch}
        />
      )}
    </div>
  );
}

type Formulario = Required<
  Pick<
    TransactionPayload,
    "type" | "category" | "amount" | "description" | "transaction_date"
  >
> & { installments: number | null; first_due_date: string };

/**
 * Lançamento novo.
 *
 * O parcelamento acontece na CRIAÇÃO porque é assim que a compra existe no
 * mundo: o papelão comprado em 3× é um lançamento com três vencimentos, não
 * três lançamentos. O servidor divide em centavos inteiros e joga a diferença
 * de arredondamento na primeira parcela.
 */
function NovoLancamento({
  aberto,
  onOpenChange,
  onSalvo,
}: {
  aberto: boolean;
  onOpenChange: (aberto: boolean) => void;
  onSalvo: () => void;
}) {
  const hoje = new Date().toISOString().slice(0, 10);

  const [form, setForm] = useState<Formulario>({
    type: "exit",
    category: "material_purchase",
    amount: null,
    description: "",
    transaction_date: hoje,
    installments: 1,
    first_due_date: hoje,
  });

  const [errors, setErrors] = useState<FieldErrors>({});
  const [erroGeral, setErroGeral] = useState<string | null>(null);
  const [salvando, setSalvando] = useState(false);

  const define = <K extends keyof Formulario>(campo: K, valor: Formulario[K]) =>
    setForm((atual) => ({ ...atual, [campo]: valor }));

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();

    setSalvando(true);
    setErrors({});
    setErroGeral(null);

    try {
      await api.finance.transactions.create(form);

      toast.success("Lançamento registrado");
      onSalvo();
      onOpenChange(false);
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
    <Sheet open={aberto} onOpenChange={onOpenChange}>
      <SheetContent>
        <form onSubmit={enviar} className="flex min-h-0 flex-1 flex-col">
          <SheetHeader>
            <SheetTitle>Novo lançamento</SheetTitle>
            <SheetDescription>
              Uma compra parcelada é UM lançamento com vários vencimentos.
            </SheetDescription>
          </SheetHeader>

          <SheetBody className="space-y-4">
            <FormError message={erroGeral} />

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label htmlFor="tipo" className="text-xs">
                  Tipo
                </Label>
                <Select
                  value={form.type}
                  onValueChange={(v) => define("type", v as TransactionTypeValue)}
                >
                  <SelectTrigger id="tipo" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="entry">Entrada</SelectItem>
                    <SelectItem value="exit">Saída</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="categoria" className="text-xs">
                  Categoria
                </Label>
                <Select
                  value={form.category}
                  onValueChange={(v) => define("category", v as TransactionCategoryValue)}
                >
                  <SelectTrigger id="categoria" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {/*
                      "Venda de orçamento" fica de fora: ela é lançada ao aprovar
                      a proposta, e o servidor recusa o lançamento manual para o
                      mesmo dinheiro não entrar duas vezes.
                    */}
                    {Object.entries(ROTULO_CATEGORIA)
                      .filter(([valor]) => valor !== "quote_sale")
                      .map(([valor, rotulo]) => (
                        <SelectItem key={valor} value={valor}>
                          {rotulo}
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <TextField
              label="Descrição"
              name="description"
              required
              value={form.description}
              onChange={(v) => define("description", v)}
              errors={errors}
              placeholder="Compra de papelão cinza — 200 folhas"
            />

            <div className="grid grid-cols-2 gap-3">
              <NumberField
                label="Valor total"
                name="amount"
                required
                value={form.amount}
                onChange={(v) => define("amount", v)}
                errors={errors}
                min={0.01}
              />

              <div className="space-y-1.5">
                <Label htmlFor="data" className="text-xs">
                  Data do lançamento
                </Label>
                <Input
                  id="data"
                  type="date"
                  value={form.transaction_date}
                  onChange={(e) => define("transaction_date", e.target.value)}
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <NumberField
                label="Parcelas"
                name="installments"
                value={form.installments}
                onChange={(v) => define("installments", v)}
                errors={errors}
                min={1}
                max={60}
              />

              <div className="space-y-1.5">
                <Label htmlFor="vencimento" className="text-xs">
                  Primeiro vencimento
                </Label>
                <Input
                  id="vencimento"
                  type="date"
                  value={form.first_due_date}
                  onChange={(e) => define("first_due_date", e.target.value)}
                />
              </div>
            </div>

            {(form.installments ?? 1) > 1 && form.amount !== null && (
              <p className="rounded-md bg-muted/60 px-3 py-2 text-xs text-muted-foreground">
                {form.installments}× de aproximadamente{" "}
                <span className="font-mono tabular-nums">
                  {formatCurrency(form.amount / (form.installments ?? 1))}
                </span>
                . A divisão exata é feita pelo servidor, em centavos inteiros.
              </p>
            )}
          </SheetBody>

          <SheetFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={salvando}
            >
              Cancelar
            </Button>
            <Button type="submit" disabled={salvando}>
              {salvando && <Loader2 className="size-4 animate-spin" />}
              Registrar
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  );
}

"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import {
  ArrowLeft,
  CheckCircle2,
  Download,
  FileText,
  Loader2,
  Trash2,
  UserPlus,
} from "lucide-react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import { Skeleton } from "@/components/ui/skeleton";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

import { ErrorState } from "@/components/data/states";
import { useApi, mensagemDeErro } from "@/hooks/useApi";
import { api, baixarArquivo } from "@/lib/api";
import { formatCurrency } from "@/lib/pricing/engine";
import { ROTULO_MODELO, ROTULO_STATUS, TOM_STATUS, formatarData } from "@/lib/rotulos";

/**
 * O orçamento gravado.
 *
 * Mostra o que foi VENDIDO, não o que a calculadora calcularia hoje: os valores
 * saem das colunas materializadas e do snapshot, congelados no momento da
 * gravação. Se o papelão encarecer amanhã, esta tela continua exibindo o preço
 * que o cliente aprovou.
 */
export default function OrcamentoPage() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const id = Number(params.id);

  const orcamento = useApi(`orcamento:${id}`, () => api.quotes.get(id));

  if (orcamento.error) {
    return <ErrorState message={orcamento.error} onRetry={orcamento.refetch} />;
  }

  if (!orcamento.data) {
    return (
      <div className="mx-auto max-w-4xl space-y-4">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  const q = orcamento.data;
  const aprovado = q.status === "approved";

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <div>
        <Button asChild variant="ghost" size="sm" className="-ml-2 text-muted-foreground">
          <Link href="/orcamentos">
            <ArrowLeft className="size-3.5" />
            Orçamentos
          </Link>
        </Button>
      </div>

      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="font-mono text-xl font-semibold">{q.reference}</h1>
            <Badge variant={TOM_STATUS[q.status]}>{ROTULO_STATUS[q.status]}</Badge>
          </div>
          <p className="text-sm text-muted-foreground">
            {q.client.name} · {formatarData(q.created_at)}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Button asChild variant="outline" size="sm">
            <Link href={`/orcamentos/${id}/ficha-tecnica`}>
              <FileText className="size-3.5" />
              Ficha técnica
            </Link>
          </Button>

          <BotaoPdf id={id} referencia={q.reference} />

          {!aprovado && <DialogoDeAprovacao id={id} onAprovado={orcamento.refetch} />}
        </div>
      </header>

      {/* ── O preço ──────────────────────────────────────────────────────── */}
      <Card className="border-primary/20 bg-primary/5">
        <CardContent className="flex flex-wrap items-end justify-between gap-4 p-5">
          <div>
            <p className="text-xs uppercase tracking-wide text-muted-foreground">
              Preço de venda
            </p>
            <p className="font-mono text-3xl font-bold tabular-nums">
              {formatCurrency(q.pricing.total_price)}
            </p>
            <p className="text-sm text-muted-foreground">
              {formatCurrency(q.pricing.unit_price, "BRL", 4)} por unidade ·{" "}
              {q.specification.quantity.toLocaleString("pt-BR")} un.
            </p>
          </div>

          <div className="text-right">
            <p className="text-xs text-muted-foreground">Lucro</p>
            <p className="font-mono text-lg font-semibold tabular-nums text-emerald-600 dark:text-emerald-500">
              {formatCurrency(q.pricing.profit_amount)}
            </p>
            <p className="text-xs text-muted-foreground">
              custo {formatCurrency(q.pricing.total_cost)}
            </p>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-4 md:grid-cols-2">
        {/* ── A caixa ────────────────────────────────────────────────────── */}
        <Card>
          <CardContent className="space-y-2.5 p-4">
            <Titulo>Especificação</Titulo>

            <Linha
              rotulo="Modelo"
              valor={ROTULO_MODELO[q.specification.box_model] ?? q.specification.box_model}
            />
            <Linha
              rotulo="Medidas internas"
              valor={`${q.specification.width_mm} × ${q.specification.height_mm} × ${q.specification.depth_mm} mm`}
            />
            <Linha
              rotulo="Material"
              valor={q.specification.material?.name ?? "—"}
            />
            <Linha
              rotulo="Quantidade"
              valor={`${q.specification.quantity.toLocaleString("pt-BR")} un.`}
            />

            <Separator />

            <Linha rotulo="Perda" valor={`${q.parameters.waste_percent}%`} />
            <Linha
              rotulo="Tempo por unidade"
              valor={`${q.parameters.production_minutes_per_unit} min`}
            />
            <Linha
              rotulo="Lucro pedido"
              valor={`${q.parameters.profit_margin_percent}% ${
                q.parameters.pricing_mode === "margin" ? "sobre a venda" : "sobre o custo"
              }`}
            />
            <Linha
              rotulo="Área total"
              valor={`${q.area.total_m2.toFixed(2)} m²`}
            />
          </CardContent>
        </Card>

        {/* ── O custo ────────────────────────────────────────────────────── */}
        <Card>
          <CardContent className="space-y-2.5 p-4">
            <Titulo>Composição por unidade</Titulo>

            <Linha rotulo="Matéria-prima" valor={formatCurrency(q.costs.material, "BRL", 4)} />
            {q.costs.wrap > 0 && (
              <Linha rotulo="Revestimento" valor={formatCurrency(q.costs.wrap, "BRL", 4)} />
            )}
            {q.costs.hardware > 0 && (
              <Linha rotulo="Ferragem" valor={formatCurrency(q.costs.hardware, "BRL", 4)} />
            )}
            <Linha rotulo="Mão de obra" valor={formatCurrency(q.costs.labor, "BRL", 4)} />
            <Linha rotulo="Hora-máquina" valor={formatCurrency(q.costs.machine, "BRL", 4)} />
            <Linha rotulo="Energia" valor={formatCurrency(q.costs.energy, "BRL", 4)} />
            {q.costs.overhead > 0 && (
              <Linha
                rotulo="Custos indiretos"
                valor={formatCurrency(q.costs.overhead, "BRL", 4)}
              />
            )}

            <Separator />

            <Linha
              rotulo="Custo unitário"
              valor={formatCurrency(q.costs.unit_cost, "BRL", 4)}
              forte
            />
          </CardContent>
        </Card>
      </div>

      {/* ── Cliente ──────────────────────────────────────────────────────── */}
      <Card>
        <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
          <div>
            <Titulo>Cliente</Titulo>
            <p className="mt-1 text-sm font-medium">{q.client.name}</p>
            <p className="text-xs text-muted-foreground">
              {[q.client.email, q.client.document].filter(Boolean).join(" · ") ||
                "sem contato informado"}
            </p>
          </div>

          {/*
            O orçamento guarda o cliente como TEXTO. Promover cria o cadastro de
            verdade, que é o que permite o histórico por cliente e o vínculo com
            o livro-caixa.
          */}
          <Button
            variant="outline"
            size="sm"
            onClick={async () => {
              try {
                await api.quotes.promoteClient(id);
                toast.success("Cliente cadastrado a partir do orçamento");
              } catch (erro) {
                toast.error(mensagemDeErro(erro));
              }
            }}
          >
            <UserPlus className="size-3.5" />
            Cadastrar como cliente
          </Button>
        </CardContent>
      </Card>

      {q.notes && (
        <Card>
          <CardContent className="space-y-1 p-4">
            <Titulo>Observações</Titulo>
            <p className="text-sm whitespace-pre-wrap">{q.notes}</p>
          </CardContent>
        </Card>
      )}

      {/* ── Excluir ──────────────────────────────────────────────────────── */}
      <div className="flex justify-end">
        <AlertDialog>
          <AlertDialogTrigger asChild>
            <Button variant="ghost" size="sm" className="text-muted-foreground">
              <Trash2 className="size-3.5" />
              Excluir orçamento
            </Button>
          </AlertDialogTrigger>

          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Excluir {q.reference}?</AlertDialogTitle>
              <AlertDialogDescription>
                O orçamento sai da lista. O histórico financeiro de um orçamento já
                aprovado não é afetado — o lançamento no caixa continua existindo.
              </AlertDialogDescription>
            </AlertDialogHeader>

            <AlertDialogFooter>
              <AlertDialogCancel>Cancelar</AlertDialogCancel>
              <AlertDialogAction
                variant="destructive"
                onClick={async () => {
                  try {
                    await api.quotes.remove(id);
                    toast.success("Orçamento excluído");
                    router.push("/orcamentos");
                  } catch (erro) {
                    toast.error(mensagemDeErro(erro));
                  }
                }}
              >
                Excluir
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </div>
    </div>
  );
}

function Titulo({ children }: { children: React.ReactNode }) {
  return (
    <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
      {children}
    </h2>
  );
}

function Linha({
  rotulo,
  valor,
  forte,
}: {
  rotulo: string;
  valor: string;
  forte?: boolean;
}) {
  return (
    <div
      className={`flex items-center justify-between gap-3 text-sm ${
        forte ? "font-semibold" : "text-muted-foreground"
      }`}
    >
      <span>{rotulo}</span>
      <span className="font-mono tabular-nums">{valor}</span>
    </div>
  );
}

/**
 * Download do PDF.
 *
 * Um `<a href>` não serve: a rota exige token e o navegador não manda o
 * cabeçalho numa navegação comum. O arquivo é buscado como blob e entregue por
 * um link temporário — ver `baixarArquivo()`.
 */
function BotaoPdf({ id, referencia }: { id: number; referencia: string }) {
  const [baixando, setBaixando] = useState(false);

  return (
    <Button
      variant="outline"
      size="sm"
      disabled={baixando}
      onClick={async () => {
        setBaixando(true);

        try {
          await baixarArquivo(api.quotes.pdfPath(id), `${referencia}.pdf`);
        } catch (erro) {
          toast.error(mensagemDeErro(erro));
        } finally {
          setBaixando(false);
        }
      }}
    >
      {baixando ? <Loader2 className="size-3.5 animate-spin" /> : <Download className="size-3.5" />}
      Proposta em PDF
    </Button>
  );
}

/**
 * Aprovação.
 *
 * Aprovar NÃO é mudar um rótulo: o servidor lança a venda no livro-caixa e
 * gera as parcelas. Por isso a confirmação diz o que vai acontecer e pergunta
 * em quantas vezes — perguntar depois seria tarde, e o lançamento já estaria
 * em parcela única.
 */
function DialogoDeAprovacao({ id, onAprovado }: { id: number; onAprovado: () => void }) {
  const [parcelas, setParcelas] = useState(1);
  const [primeiroVencimento, setPrimeiroVencimento] = useState("");
  const [salvando, setSalvando] = useState(false);

  return (
    <AlertDialog>
      <AlertDialogTrigger asChild>
        <Button size="sm">
          <CheckCircle2 className="size-3.5" />
          Aprovar
        </Button>
      </AlertDialogTrigger>

      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Aprovar este orçamento?</AlertDialogTitle>
          <AlertDialogDescription>
            A venda entra no livro-caixa e as parcelas são criadas com os
            vencimentos abaixo. A divisão é em centavos inteiros — a diferença de
            arredondamento vai para a primeira parcela.
          </AlertDialogDescription>
        </AlertDialogHeader>

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="parcelas" className="text-xs">
              Parcelas
            </Label>
            <Input
              id="parcelas"
              type="number"
              min={1}
              max={60}
              value={parcelas}
              onChange={(e) => setParcelas(Math.max(1, Number(e.target.value) || 1))}
              className="font-mono tabular-nums"
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="vencimento" className="text-xs">
              Primeiro vencimento
            </Label>
            <Input
              id="vencimento"
              type="date"
              value={primeiroVencimento}
              onChange={(e) => setPrimeiroVencimento(e.target.value)}
            />
          </div>
        </div>

        <AlertDialogFooter>
          <AlertDialogCancel disabled={salvando}>Cancelar</AlertDialogCancel>
          <AlertDialogAction
            disabled={salvando}
            onClick={async (evento) => {
              // Segura o fechamento até a API responder: fechado antes, um erro
              // apareceria como toast sobre uma tela que já mudou de assunto.
              evento.preventDefault();
              setSalvando(true);

              try {
                await api.quotes.approve(id, {
                  installments: parcelas,
                  first_due_date: primeiroVencimento || undefined,
                });

                toast.success("Orçamento aprovado e lançado no caixa");
                onAprovado();
              } catch (erro) {
                toast.error(mensagemDeErro(erro));
              } finally {
                setSalvando(false);
              }
            }}
          >
            {salvando && <Loader2 className="size-4 animate-spin" />}
            Aprovar e lançar
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}

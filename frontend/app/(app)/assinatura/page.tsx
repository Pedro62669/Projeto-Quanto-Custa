"use client";

import { useState } from "react";
import { CheckCircle2, ExternalLink, Loader2, ShieldCheck } from "lucide-react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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

import { PageHeader } from "@/components/PageHeader";
import { ErrorState } from "@/components/data/states";
import { useApi, mensagemDeErro } from "@/hooks/useApi";
import { api, type SubscriptionContext } from "@/lib/api";
import { formatCurrency } from "@/lib/pricing/engine";
import { formatarData } from "@/lib/rotulos";
import { session } from "@/lib/auth";
import { useRouter } from "next/navigation";

/**
 * Plano, cotas e cobrança.
 *
 * O direito de arrependimento do CDC aparece em destaque, não em letra miúda: o
 * artigo 49 exige informação clara e ostensiva, e um botão de cancelar que não
 * diz "você ainda tem direito ao dinheiro de volta" cumpre a letra da lei e
 * falha o propósito dela.
 */
export default function AssinaturaPage() {
  const assinatura = useApi("assinatura", () => api.billing.subscription());

  if (assinatura.error) {
    return <ErrorState message={assinatura.error} onRetry={assinatura.refetch} />;
  }

  if (!assinatura.data) {
    return (
      <div className="mx-auto max-w-3xl space-y-4">
        <Skeleton className="h-10 w-52" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  const a = assinatura.data;

  return (
    <div className="mx-auto max-w-3xl space-y-5">
      <PageHeader
        title="Plano e assinatura"
        description="O plano vigente, o consumo das cotas e a cobrança."
      />

      <PlanoAtual assinatura={a} />
      <Cotas cotas={a.cotas} />
      <EscolhaDePlano atual={a.plano.tipo} planos={a.planos_disponiveis} />

      {a.assinatura && (
        <CancelamentoCard assinatura={a} onCancelado={assinatura.refetch} />
      )}

      <ExclusaoDeConta />
    </div>
  );
}

function PlanoAtual({ assinatura: a }: { assinatura: SubscriptionContext }) {
  const divergente = a.plano.tipo !== a.plano.contratado;

  return (
    <Card className="border-primary/20 bg-primary/5">
      <CardContent className="space-y-2 p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className="text-xs uppercase tracking-wide text-muted-foreground">
              Plano vigente
            </p>
            <p className="text-2xl font-semibold">{a.plano.rotulo}</p>
            <p className="text-sm text-muted-foreground">
              {a.plano.mensalidade > 0
                ? `${formatCurrency(a.plano.mensalidade)} por mês`
                : "Sem mensalidade"}
            </p>
          </div>

          <Badge variant={a.acesso_liberado ? "secondary" : "destructive"}>
            {a.plano.situacao_rotulo}
          </Badge>
        </div>

        {a.em_teste && a.trial_ends_at && (
          <p className="rounded-md bg-background/70 px-3 py-2 text-xs">
            Teste até <strong className="font-medium">{formatarData(a.trial_ends_at)}</strong>.
            Terminado o prazo, a conta passa para o plano gratuito — nada é
            bloqueado, e os orçamentos continuam onde estão.
          </p>
        )}

        {/*
          Contratado ≠ vigente acontece entre o fim do teste e a passagem do
          cron. Mostrar só o contratado faria a tela prometer um limite que o
          servidor recusa.
        */}
        {divergente && !a.em_teste && (
          <p className="text-xs text-muted-foreground">
            Plano contratado: {a.plano.contratado}. As cotas abaixo seguem o
            vigente.
          </p>
        )}
      </CardContent>
    </Card>
  );
}

function Cotas({ cotas }: { cotas: SubscriptionContext["cotas"] }) {
  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          Consumo do plano
        </h2>

        <div className="grid gap-3 sm:grid-cols-3">
          {Object.entries(cotas).map(([chave, cota]) => {
            const ilimitado = cota.limite === null;
            const proporcao = ilimitado ? 0 : Math.min(cota.usado / (cota.limite || 1), 1);

            return (
              <div key={chave} className="space-y-1">
                <div className="flex items-baseline justify-between text-xs">
                  <span className="text-muted-foreground">{cota.rotulo}</span>
                  <span className="font-mono tabular-nums">
                    {cota.usado}
                    {ilimitado ? " · sem limite" : ` / ${cota.limite}`}
                  </span>
                </div>

                {/* Ilimitado não tem barra: ela sugeriria um teto inexistente. */}
                {!ilimitado && (
                  <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                      className={`h-full ${proporcao >= 0.8 ? "bg-amber-500" : "bg-primary"}`}
                      style={{ width: `${proporcao * 100}%` }}
                    />
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </CardContent>
    </Card>
  );
}

function EscolhaDePlano({
  atual,
  planos,
}: {
  atual: string;
  planos: SubscriptionContext["planos_disponiveis"];
}) {
  const [contratando, setContratando] = useState<string | null>(null);

  async function assinar(tipo: string) {
    setContratando(tipo);

    try {
      const sessao = await api.billing.checkout(tipo);

      // O checkout abre no gateway. A assinatura só nasce quando o webhook
      // confirmar o pagamento — gravar antes produziria "assinantes" que
      // desistiram na tela do cartão.
      //
      // `assign` em vez de atribuir a `location.href`: o compilador do React
      // trata a atribuição como mutação de valor externo, e o método diz a mesma
      // coisa sem a ambiguidade.
      window.location.assign(sessao.url);
    } catch (erro) {
      toast.error(mensagemDeErro(erro));
      setContratando(null);
    }
  }

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          Planos
        </h2>

        <div className="grid gap-3 sm:grid-cols-3">
          {planos.map((plano) => {
            const vigente = plano.tipo === atual;

            return (
              <div
                key={plano.tipo}
                className={`space-y-2 rounded-lg border p-3 ${
                  vigente ? "border-primary bg-primary/5" : ""
                }`}
              >
                <div className="flex items-center justify-between">
                  <p className="text-sm font-medium">{plano.rotulo}</p>
                  {vigente && <CheckCircle2 className="size-4 text-primary" />}
                </div>

                <p className="font-mono text-lg font-semibold tabular-nums">
                  {plano.pago ? formatCurrency(plano.mensalidade) : "Grátis"}
                </p>

                {/* O que o plano entrega, ao lado do que ele custa: um cartão
                    só com preço vende sem dizer o que está vendendo. */}
                <ul className="space-y-0.5 text-[11px] text-muted-foreground">
                  <li>{descreveLimite(plano.limites.materiais, "materiais")}</li>
                  <li>
                    {descreveLimite(plano.limites.orcamentos_por_mes, "orçamentos/mês")}
                  </li>
                  <li>{descreveLimite(plano.limites.clientes, "clientes")}</li>
                </ul>

                {!vigente && plano.pago && (
                  <Button
                    size="sm"
                    variant="outline"
                    className="w-full"
                    disabled={contratando !== null}
                    onClick={() => assinar(plano.tipo)}
                  >
                    {contratando === plano.tipo ? (
                      <Loader2 className="size-3.5 animate-spin" />
                    ) : (
                      <ExternalLink className="size-3.5" />
                    )}
                    Assinar
                  </Button>
                )}
              </div>
            );
          })}
        </div>

        <p className="text-xs text-muted-foreground">
          O pagamento acontece no gateway. A assinatura só é ativada quando o
          pagamento é confirmado.
        </p>
      </CardContent>
    </Card>
  );
}

/** Null é ilimitado — o mesmo contrato do resumo de cotas. */
function descreveLimite(limite: number | null, o_que: string): string {
  return limite === null ? `${o_que} sem limite` : `${limite} ${o_que}`;
}

/**
 * Cancelamento, com o arrependimento em destaque.
 *
 * Dentro dos 7 dias do artigo 49 do CDC o estorno é INTEGRAL e o acesso termina
 * hoje. Fora do prazo, não há devolução e o acesso vai até o fim do período já
 * pago — as duas coisas ditas antes do clique, não depois.
 */
function CancelamentoCard({
  assinatura: a,
  onCancelado,
}: {
  assinatura: SubscriptionContext;
  onCancelado: () => void;
}) {
  const [senha, setSenha] = useState("");
  const [cancelando, setCancelando] = useState(false);

  const arrependimento = a.assinatura?.arrependimento_disponivel === true;

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          Cancelamento
        </h2>

        {arrependimento ? (
          <div className="flex items-start gap-2 rounded-md bg-emerald-500/10 px-3 py-2 text-xs text-emerald-700 dark:text-emerald-500">
            <ShieldCheck className="mt-0.5 size-4 shrink-0" />
            <p>
              <strong className="font-medium">Direito de arrependimento ativo</strong>{" "}
              (art. 49 do CDC). Cancelando até{" "}
              {formatarData(a.assinatura?.arrependimento_ate)}, o valor pago é
              devolvido integralmente.
            </p>
          </div>
        ) : (
          <p className="text-xs text-muted-foreground">
            O prazo de arrependimento já passou. Cancelando agora, não há devolução
            e o acesso continua até{" "}
            {formatarData(a.assinatura?.current_period_ends_at)}.
          </p>
        )}

        <AlertDialog>
          <AlertDialogTrigger asChild>
            <Button variant="outline" size="sm">
              Cancelar assinatura
            </Button>
          </AlertDialogTrigger>

          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Cancelar a assinatura?</AlertDialogTitle>
              <AlertDialogDescription>
                {arrependimento
                  ? "O valor pago será devolvido integralmente e o acesso ao plano termina hoje. A conta continua existindo no plano gratuito."
                  : `Sem devolução, e o acesso ao plano segue até ${formatarData(a.assinatura?.current_period_ends_at)}. Depois disso a conta passa para o plano gratuito.`}
              </AlertDialogDescription>
            </AlertDialogHeader>

            <div className="space-y-1.5">
              <Label htmlFor="senha-cancelar" className="text-xs">
                Confirme sua senha
              </Label>
              <Input
                id="senha-cancelar"
                type="password"
                value={senha}
                onChange={(e) => setSenha(e.target.value)}
              />
            </div>

            <AlertDialogFooter>
              <AlertDialogCancel disabled={cancelando}>Manter assinatura</AlertDialogCancel>
              <AlertDialogAction
                variant="destructive"
                disabled={cancelando || !senha}
                onClick={async (evento) => {
                  evento.preventDefault();
                  setCancelando(true);

                  try {
                    await api.billing.cancel({ password: senha });
                    toast.success("Assinatura cancelada");
                    setSenha("");
                    onCancelado();
                  } catch (erro) {
                    toast.error(mensagemDeErro(erro));
                  } finally {
                    setCancelando(false);
                  }
                }}
              >
                {cancelando && <Loader2 className="size-4 animate-spin" />}
                Cancelar assinatura
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </CardContent>
    </Card>
  );
}

/**
 * Exclusão da conta — LGPD art. 18.
 *
 * O direito existe no servidor desde a Fase 6 e não tinha botão. Pede senha e a
 * palavra EXCLUIR digitada: é irreversível, apaga a empresa inteira, e um clique
 * distraído não deveria conseguir fazer isso.
 */
function ExclusaoDeConta() {
  const router = useRouter();

  const [senha, setSenha] = useState("");
  const [confirmacao, setConfirmacao] = useState("");
  const [excluindo, setExcluindo] = useState(false);

  return (
    <Card className="border-destructive/30">
      <CardContent className="space-y-3 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wider text-destructive">
          Excluir a conta
        </h2>

        <p className="text-xs text-muted-foreground">
          Apaga a empresa, os orçamentos, os cadastros e o histórico financeiro.
          Não há como desfazer. É o seu direito pela LGPD, e ele é definitivo.
        </p>

        <AlertDialog>
          <AlertDialogTrigger asChild>
            <Button variant="destructive" size="sm">
              Excluir conta e dados
            </Button>
          </AlertDialogTrigger>

          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Excluir definitivamente?</AlertDialogTitle>
              <AlertDialogDescription>
                Tudo que a empresa tem no sistema será apagado. Nenhuma cópia fica
                guardada, e não existe caminho de volta.
              </AlertDialogDescription>
            </AlertDialogHeader>

            <div className="space-y-3">
              <div className="space-y-1.5">
                <Label htmlFor="senha-excluir" className="text-xs">
                  Sua senha
                </Label>
                <Input
                  id="senha-excluir"
                  type="password"
                  value={senha}
                  onChange={(e) => setSenha(e.target.value)}
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="confirmar-excluir" className="text-xs">
                  Digite EXCLUIR para confirmar
                </Label>
                <Input
                  id="confirmar-excluir"
                  value={confirmacao}
                  onChange={(e) => setConfirmacao(e.target.value.toUpperCase())}
                  className="font-mono"
                />
              </div>
            </div>

            <AlertDialogFooter>
              <AlertDialogCancel disabled={excluindo}>Cancelar</AlertDialogCancel>
              <AlertDialogAction
                variant="destructive"
                disabled={excluindo || !senha || confirmacao !== "EXCLUIR"}
                onClick={async (evento) => {
                  evento.preventDefault();
                  setExcluindo(true);

                  try {
                    await api.auth.deleteAccount({ password: senha, confirmacao: "EXCLUIR" });

                    // A sessão morreu junto com a conta: limpar o token local
                    // evita um 401 na próxima navegação.
                    await session.logout().catch(() => {});
                    router.replace("/login");
                  } catch (erro) {
                    toast.error(mensagemDeErro(erro));
                    setExcluindo(false);
                  }
                }}
              >
                {excluindo && <Loader2 className="size-4 animate-spin" />}
                Excluir tudo
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </CardContent>
    </Card>
  );
}

"use client";

import { useState } from "react";
import { KeyRound, LogOut, ShieldCheck } from "lucide-react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";

import { PageHeader } from "@/components/PageHeader";
import {
  DataTable,
  EmptyState,
  Pagination,
  type Column,
} from "@/components/data/DataTable";
import { ErrorState } from "@/components/data/states";

import { useApi, mensagemDeErro } from "@/hooks/useApi";
import { api, type PlatformTenant, type PlatformUser } from "@/lib/api";
import { formatCurrency } from "@/lib/pricing/engine";
import { formatarData } from "@/lib/rotulos";
import { useAccount, selectIsPlatformAdmin } from "@/store/useAccount";

/**
 * Painel da plataforma — quem opera o SaaS, não quem o usa.
 *
 * O servidor responde 404 a quem não é admin de plataforma, e não 403: a
 * existência do painel não é revelada a quem não deveria conhecê-lo. A tela
 * respeita a mesma postura — para os outros, esta rota simplesmente não existe.
 */
export default function PlataformaPage() {
  const ehAdminDePlataforma = useAccount(selectIsPlatformAdmin);
  const carregando = useAccount((s) => s.loading);

  if (carregando) return <Skeleton className="h-96 w-full" />;

  if (!ehAdminDePlataforma) {
    return (
      <div className="mx-auto max-w-md py-16 text-center">
        <h1 className="text-lg font-semibold">Página não encontrada</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          O endereço que você tentou abrir não existe.
        </p>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <PageHeader
        title="Plataforma"
        description="Operação do SaaS: assinantes, faturamento e contas."
      />

      <Tabs defaultValue="metricas">
        <TabsList>
          <TabsTrigger value="metricas">Métricas</TabsTrigger>
          <TabsTrigger value="empresas">Empresas</TabsTrigger>
          <TabsTrigger value="usuarios">Usuários</TabsTrigger>
        </TabsList>

        <TabsContent value="metricas">
          <Metricas />
        </TabsContent>
        <TabsContent value="empresas">
          <Empresas />
        </TabsContent>
        <TabsContent value="usuarios">
          <Usuarios />
        </TabsContent>
      </Tabs>
    </div>
  );
}

function Metricas() {
  const painel = useApi("plataforma:dashboard", () => api.platform.dashboard());

  if (painel.error) return <ErrorState message={painel.error} onRetry={painel.refetch} />;
  if (!painel.data) return <Skeleton className="h-64 w-full" />;

  const faturamento = (painel.data.faturamento ?? {}) as Record<string, number>;
  const assinantes = (painel.data.assinantes ?? {}) as Record<string, unknown>;

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-4">
        <Metrica rotulo="MRR" valor={formatCurrency(faturamento.mrr ?? 0)} />
        <Metrica rotulo="Bruto no mês" valor={formatCurrency(faturamento.bruto_mes ?? 0)} />
        <Metrica
          rotulo="Estornado"
          valor={formatCurrency(faturamento.estornado_mes ?? 0)}
          negativo={(faturamento.estornado_mes ?? 0) > 0}
        />
        <Metrica
          rotulo="Líquido"
          valor={formatCurrency(faturamento.liquido ?? 0)}
          destaque
        />
      </div>

      <Card>
        <CardContent className="space-y-2 p-4">
          <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Assinantes por plano
          </h2>

          <pre className="overflow-x-auto rounded-md bg-muted/50 p-3 font-mono text-[11px]">
            {JSON.stringify(assinantes, null, 2)}
          </pre>
        </CardContent>
      </Card>
    </div>
  );
}

function Metrica({
  rotulo,
  valor,
  negativo,
  destaque,
}: {
  rotulo: string;
  valor: string;
  negativo?: boolean;
  destaque?: boolean;
}) {
  return (
    <Card className={destaque ? "border-primary/20 bg-primary/5" : ""}>
      <CardContent className="space-y-0.5 p-4">
        <p className="text-xs text-muted-foreground">{rotulo}</p>
        <p
          className={`font-mono text-lg font-semibold tabular-nums ${
            negativo ? "text-destructive" : ""
          }`}
        >
          {valor}
        </p>
      </CardContent>
    </Card>
  );
}

function Empresas() {
  const [pagina, setPagina] = useState(1);
  const lista = useApi(`plataforma:empresas:${pagina}`, () =>
    api.platform.tenants.list({ page: pagina }),
  );

  const colunas: Column<PlatformTenant>[] = [
    {
      header: "Empresa",
      render: (t) => (
        <div className="min-w-0">
          <p className="truncate font-medium">{t.name}</p>
          <p className="truncate text-xs text-muted-foreground">
            {String(t.document ?? "sem documento")} ·{" "}
            {String(t.users_count ?? 0)} usuário(s)
          </p>
        </div>
      ),
    },
    {
      header: "Plano",
      render: (t) => (
        <div>
          <Badge variant="secondary" className="text-[10px]">
            {String(t.plan_label ?? t.plan_type)}
          </Badge>
          <p className="mt-0.5 text-[11px] text-muted-foreground">
            {String(t.plan_status_label ?? t.plan_status)}
          </p>
        </div>
      ),
    },
    {
      header: "Desde",
      render: (t) => (
        <span className="font-mono text-xs tabular-nums text-muted-foreground">
          {formatarData(t.created_at)}
        </span>
      ),
    },
    {
      header: "Ativa",
      className: "w-20 text-right",
      render: (t) => (
        <Switch
          checked={t.is_active}
          aria-label={`${t.is_active ? "Suspender" : "Reativar"} ${t.name}`}
          onCheckedChange={async (ativo) => {
            try {
              await api.platform.suspend(t.id, ativo);
              toast.success(ativo ? "Empresa reativada" : "Empresa suspensa");
              lista.refetch();
            } catch (erro) {
              toast.error(mensagemDeErro(erro));
            }
          }}
        />
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <DataTable
        columns={colunas}
        rows={lista.data?.items ?? []}
        loading={lista.loading}
        error={lista.error}
        onRetry={lista.refetch}
        rowKey={(t) => t.id}
        empty={<EmptyState title="Nenhuma empresa cadastrada" />}
      />

      <Pagination page={lista.data} onChange={setPagina} />
    </div>
  );
}

function Usuarios() {
  const [pagina, setPagina] = useState(1);
  const lista = useApi(`plataforma:usuarios:${pagina}`, () =>
    api.platform.users.list({ page: pagina }),
  );

  const colunas: Column<PlatformUser>[] = [
    {
      header: "Usuário",
      render: (u) => (
        <div className="min-w-0">
          <p className="truncate font-medium">
            {u.name}
            {u.role === "platform_admin" && (
              <ShieldCheck className="ml-1 inline size-3.5 text-primary" />
            )}
          </p>
          <p className="truncate text-xs text-muted-foreground">{u.email}</p>
        </div>
      ),
    },
    {
      header: "Empresa",
      render: (u) => (
        <span className="text-xs">{u.tenant?.name ?? "— plataforma —"}</span>
      ),
    },
    {
      header: "Último acesso",
      render: (u) => (
        <span className="font-mono text-xs tabular-nums text-muted-foreground">
          {u.last_login_at ? formatarData(u.last_login_at) : "nunca"}
        </span>
      ),
    },
    {
      header: "",
      className: "w-48 text-right",
      render: (u) => (
        <div className="flex items-center justify-end gap-1">
          {/* Derrubar a sessão e mandar redefinição são as duas ações de
              SUPORTE: alguém ligou dizendo que perdeu a senha ou que o
              computador foi roubado. */}
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={`Enviar redefinição de senha para ${u.name}`}
            onClick={async () => {
              try {
                await api.platform.sendPasswordReset(u.id);
                toast.success("Link de redefinição enviado");
              } catch (erro) {
                toast.error(mensagemDeErro(erro));
              }
            }}
          >
            <KeyRound />
          </Button>

          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={`Encerrar sessões de ${u.name}`}
            onClick={async () => {
              try {
                await api.platform.forceLogout(u.id);
                toast.success("Sessões encerradas");
              } catch (erro) {
                toast.error(mensagemDeErro(erro));
              }
            }}
          >
            <LogOut />
          </Button>

          <Switch
            checked={u.is_active}
            aria-label={`${u.is_active ? "Bloquear" : "Liberar"} ${u.name}`}
            onCheckedChange={async (ativo) => {
              try {
                await api.platform.toggleUser(u.id, ativo);
                toast.success(ativo ? "Acesso liberado" : "Acesso bloqueado");
                lista.refetch();
              } catch (erro) {
                toast.error(mensagemDeErro(erro));
              }
            }}
          />
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <DataTable
        columns={colunas}
        rows={lista.data?.items ?? []}
        loading={lista.loading}
        error={lista.error}
        onRetry={lista.refetch}
        rowKey={(u) => u.id}
        empty={<EmptyState title="Nenhum usuário" />}
      />

      <Pagination page={lista.data} onChange={setPagina} />
    </div>
  );
}

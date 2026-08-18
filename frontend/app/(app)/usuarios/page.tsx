"use client";

import { useState } from "react";
import { Loader2, Pencil, Plus } from "lucide-react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
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
import { DataTable, EmptyState, type Column } from "@/components/data/DataTable";
import { FormError, TextField } from "@/components/form/Field";
import type { FieldErrors } from "@/components/form/Field";

import { useApi, mensagemDeErro } from "@/hooks/useApi";
import { api, ApiError, type CompanyUser } from "@/lib/api";
import { useAccount } from "@/store/useAccount";
import { formatarData } from "@/lib/rotulos";

/**
 * Usuários da empresa.
 *
 * Quem entra no sistema, e com qual poder. `admin` mexe em cadastro, custo e
 * assinatura; `user` orça e lança. A separação existe porque quem faz orçamento
 * o dia inteiro não deveria conseguir republicar a tabela de custos por engano.
 */
export default function UsuariosPage() {
  const eu = useAccount((s) => s.account?.user.id ?? null);

  const lista = useApi("usuarios", () => api.company.users.list());

  const [emEdicao, setEmEdicao] = useState<CompanyUser | null>(null);
  const [formAberto, setFormAberto] = useState(false);

  const colunas: Column<CompanyUser>[] = [
    {
      header: "Usuário",
      render: (u) => (
        <div className="min-w-0">
          <p className="truncate font-medium">
            {u.name}
            {u.id === eu && (
              <span className="ml-1 text-xs font-normal text-muted-foreground">(você)</span>
            )}
          </p>
          <p className="truncate text-xs text-muted-foreground">{u.email}</p>
        </div>
      ),
    },
    {
      header: "Papel",
      render: (u) => (
        <Badge variant={u.role === "admin" ? "secondary" : "outline"} className="text-[10px]">
          {u.role === "admin" ? "Administrador" : "Operação"}
        </Badge>
      ),
    },
    {
      header: "Último acesso",
      render: (u) => (
        <span className="font-mono text-xs tabular-nums text-muted-foreground">
          {u.last_login_at ? formatarData(u.last_login_at) : "nunca entrou"}
        </span>
      ),
    },
    {
      header: "",
      className: "w-20 text-right",
      render: (u) => (
        <div className="flex items-center justify-end gap-1">
          {!u.is_active && (
            <Badge variant="outline" className="text-[10px]">
              inativo
            </Badge>
          )}
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={`Editar ${u.name}`}
            onClick={() => {
              setEmEdicao(u);
              setFormAberto(true);
            }}
          >
            <Pencil />
          </Button>
        </div>
      ),
    },
  ];

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <PageHeader
        title="Usuários"
        description="Quem entra no sistema. Administrador mexe em custos e assinatura; operação orça e lança."
        actions={
          <Button
            onClick={() => {
              setEmEdicao(null);
              setFormAberto(true);
            }}
          >
            <Plus className="size-4 text-brand-on-inverted" />
            Novo usuário
          </Button>
        }
      />

      <DataTable
        columns={colunas}
        rows={lista.data ?? []}
        loading={lista.loading}
        error={lista.error}
        onRetry={lista.refetch}
        rowKey={(u) => u.id}
        empty={<EmptyState title="Nenhum usuário" description="Sua conta é a única." />}
      />

      {formAberto && (
        <FormularioDeUsuario
          key={emEdicao?.id ?? "novo"}
          usuario={emEdicao}
          aberto={formAberto}
          onOpenChange={setFormAberto}
          onSalvo={lista.refetch}
        />
      )}
    </div>
  );
}

function FormularioDeUsuario({
  usuario,
  aberto,
  onOpenChange,
  onSalvo,
}: {
  usuario: CompanyUser | null;
  aberto: boolean;
  onOpenChange: (aberto: boolean) => void;
  onSalvo: () => void;
}) {
  const [form, setForm] = useState({
    name: usuario?.name ?? "",
    email: usuario?.email ?? "",
    password: "",
    role: usuario?.role ?? "user",
    is_active: usuario?.is_active ?? true,
  });

  const [errors, setErrors] = useState<FieldErrors>({});
  const [erroGeral, setErroGeral] = useState<string | null>(null);
  const [salvando, setSalvando] = useState(false);

  const define = <K extends keyof typeof form>(campo: K, valor: (typeof form)[K]) =>
    setForm((atual) => ({ ...atual, [campo]: valor }));

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();

    setSalvando(true);
    setErrors({});
    setErroGeral(null);

    try {
      if (usuario) {
        // Senha em branco significa "não mexer": mandá-la vazia trocaria a
        // senha de quem só queria corrigir o nome.
        const { password, ...resto } = form;

        await api.company.users.update(usuario.id, password ? form : resto);
      } else {
        await api.company.users.create(form);
      }

      toast.success(usuario ? "Usuário atualizado" : "Usuário criado");
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
            <SheetTitle>{usuario ? "Editar usuário" : "Novo usuário"}</SheetTitle>
            <SheetDescription>
              O acesso é por e-mail e senha, e vale só para esta empresa.
            </SheetDescription>
          </SheetHeader>

          <SheetBody className="space-y-4">
            <FormError message={erroGeral} />

            <TextField
              label="Nome"
              name="name"
              required
              value={form.name}
              onChange={(v) => define("name", v)}
              errors={errors}
            />

            <TextField
              label="E-mail"
              name="email"
              type="email"
              required
              value={form.email}
              onChange={(v) => define("email", v)}
              errors={errors}
            />

            <TextField
              label={usuario ? "Nova senha" : "Senha"}
              name="password"
              type="password"
              required={!usuario}
              value={form.password}
              onChange={(v) => define("password", v)}
              errors={errors}
              hint={
                usuario
                  ? "Deixe em branco para manter a senha atual."
                  : "Mínimo de 8 caracteres."
              }
            />

            <div className="space-y-1.5">
              <Label htmlFor="papel" className="text-xs">
                Papel
              </Label>
              <Select
                value={form.role}
                onValueChange={(v) => define("role", v as "admin" | "user")}
              >
                <SelectTrigger id="papel" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="user">Operação — orça e lança</SelectItem>
                  <SelectItem value="admin">
                    Administrador — mexe em custos e assinatura
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="flex items-center justify-between rounded-lg border p-3">
              <div>
                <Label htmlFor="usuario-ativo" className="text-sm">
                  Acesso liberado
                </Label>
                <p className="text-xs text-muted-foreground">
                  Desativado, a pessoa não entra — mas o histórico dela permanece.
                </p>
              </div>
              <Switch
                id="usuario-ativo"
                checked={form.is_active}
                onCheckedChange={(v) => define("is_active", v)}
              />
            </div>
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
              Salvar
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  );
}

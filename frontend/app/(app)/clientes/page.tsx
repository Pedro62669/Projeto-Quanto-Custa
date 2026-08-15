"use client";

import Link from "next/link";

import { CadastroSimples, SeloAtivo } from "@/components/cadastro/CadastroSimples";
import { TextField } from "@/components/form/Field";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import { api, type Client } from "@/lib/api";

interface Formulario {
  name: string;
  cpf_cnpj: string;
  email: string;
  phone: string;
  city: string;
  state: string;
  address: string;
  is_active: boolean;
}

/**
 * Clientes.
 *
 * O orçamento guarda o cliente como TEXTO — é o que permite orçar para alguém
 * que ligou uma vez sem criar cadastro. Esta tela é para os que voltaram: a
 * partir dela vem o histórico por cliente e o vínculo com o livro-caixa.
 */
export default function ClientesPage() {
  return (
    <CadastroSimples<Client, Formulario>
      titulo="Clientes"
      descricao="Quem compra recorrente. O orçamento avulso não precisa de cadastro — este é para quem voltou."
      substantivo="cliente"
      chave="clientes"
      identidade={(c) => c.id}
      rotulo={(c) => c.name}
      vazioDescricao="Um orçamento aprovado pode virar cliente com um clique, na tela do próprio orçamento."
      colunas={[
        {
          header: "Cliente",
          render: (c) => (
            <div className="flex items-center gap-2">
              <div className="min-w-0">
                {/* O nome leva à ficha: histórico de orçamentos e movimento no
                    caixa. É o que o cadastro passou a valer quando o orçamento
                    ganhou `client_id`. */}
                <Link
                  href={`/clientes/${c.id}`}
                  className="block truncate font-medium hover:underline"
                >
                  {c.name}
                </Link>
                <p className="truncate text-xs text-muted-foreground">
                  {c.cpf_cnpj ?? "sem documento"}
                </p>
              </div>
              <SeloAtivo ativo={c.is_active} />
            </div>
          ),
        },
        {
          header: "Contato",
          render: (c) => (
            <div className="min-w-0 text-xs">
              <p className="truncate">{c.email ?? "—"}</p>
              <p className="truncate text-muted-foreground">{c.phone ?? ""}</p>
            </div>
          ),
        },
        {
          header: "Cidade",
          render: (c) => (
            <span className="text-xs">
              {[c.city, c.state].filter(Boolean).join(" · ") || "—"}
            </span>
          ),
        },
      ]}
      vazio={{
        name: "",
        cpf_cnpj: "",
        email: "",
        phone: "",
        city: "",
        state: "",
        address: "",
        is_active: true,
      }}
      paraFormulario={(c) => ({
        name: c.name,
        cpf_cnpj: c.cpf_cnpj ?? "",
        email: c.email ?? "",
        phone: c.phone ?? "",
        city: c.city ?? "",
        state: c.state ?? "",
        address: c.address ?? "",
        is_active: c.is_active,
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
            label="CPF ou CNPJ"
            name="cpf_cnpj"
            value={form.cpf_cnpj}
            onChange={(v) => define("cpf_cnpj", v)}
            errors={errors}
          />

          <div className="grid grid-cols-2 gap-3">
            <TextField
              label="E-mail"
              name="email"
              type="email"
              value={form.email}
              onChange={(v) => define("email", v)}
              errors={errors}
            />
            <TextField
              label="Telefone"
              name="phone"
              value={form.phone}
              onChange={(v) => define("phone", v)}
              errors={errors}
            />
          </div>

          <TextField
            label="Endereço"
            name="address"
            value={form.address}
            onChange={(v) => define("address", v)}
            errors={errors}
          />

          <div className="grid grid-cols-3 gap-3">
            <TextField
              label="Cidade"
              name="city"
              value={form.city}
              onChange={(v) => define("city", v)}
              errors={errors}
              className="col-span-2"
            />
            <TextField
              label="UF"
              name="state"
              value={form.state}
              onChange={(v) => define("state", v.toUpperCase())}
              errors={errors}
              maxLength={2}
            />
          </div>

          <div className="flex items-center justify-between rounded-lg border p-3">
            <Label htmlFor="cliente-ativo" className="text-sm">
              Cliente ativo
            </Label>
            <Switch
              id="cliente-ativo"
              checked={form.is_active}
              onCheckedChange={(v) => define("is_active", v)}
            />
          </div>
        </>
      )}
      api={{
        list: api.clients.list,
        create: (form) => api.clients.create(paraPayload(form)),
        update: (id, form) => api.clients.update(id, paraPayload(form)),
        remove: api.clients.remove,
      }}
    />
  );
}

/** Campo vazio vira null: "" não passa nas regras de e-mail e UF do servidor. */
function paraPayload(form: Formulario) {
  return {
    ...form,
    cpf_cnpj: form.cpf_cnpj || null,
    email: form.email || null,
    phone: form.phone || null,
    city: form.city || null,
    state: form.state || null,
    address: form.address || null,
  };
}

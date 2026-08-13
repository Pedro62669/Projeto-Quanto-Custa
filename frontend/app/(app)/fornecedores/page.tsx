"use client";

import { CadastroSimples, SeloAtivo } from "@/components/cadastro/CadastroSimples";
import { SeletorDeMateriais } from "@/components/cadastro/SeletorDeMateriais";
import { TextField } from "@/components/form/Field";
import { Badge } from "@/components/ui/badge";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import { api, type Supplier } from "@/lib/api";

interface Formulario {
  name: string;
  contact_name: string;
  email: string;
  phone: string;
  city: string;
  state: string;
  is_active: boolean;
  material_ids: number[];
}

/**
 * Fornecedores.
 *
 * De quem vem o papelão. Serve ao lançamento de compra no livro-caixa — é o
 * vínculo que permite responder "quanto gastei com a Papelaria X este ano".
 */
export default function FornecedoresPage() {
  return (
    <CadastroSimples<Supplier, Formulario>
      titulo="Fornecedores"
      descricao="De quem vem a matéria-prima. Aparece nos lançamentos de compra do livro-caixa."
      substantivo="fornecedor"
      chave="fornecedores"
      identidade={(f) => f.id}
      rotulo={(f) => f.name}
      vazioDescricao="Cadastre quem fornece papelão, papel e ferragem para acompanhar o gasto por fornecedor."
      colunas={[
        {
          header: "Fornecedor",
          render: (f) => (
            <div className="flex items-center gap-2">
              <div className="min-w-0">
                <p className="truncate font-medium">{f.name}</p>
                <p className="truncate text-xs text-muted-foreground">
                  {f.contact_name ?? "sem contato"}
                </p>
              </div>
              <SeloAtivo ativo={f.is_active} />
            </div>
          ),
        },
        {
          header: "Contato",
          render: (f) => (
            <div className="min-w-0 text-xs">
              <p className="truncate">{f.email ?? "—"}</p>
              <p className="truncate text-muted-foreground">{f.phone ?? ""}</p>
            </div>
          ),
        },
        {
          header: "Fornece",
          render: (f) => <Etiquetas materiais={f.materials} />,
        },
        {
          header: "Cidade",
          render: (f) => (
            <span className="text-xs">
              {[f.city, f.state].filter(Boolean).join(" · ") || "—"}
            </span>
          ),
        },
      ]}
      vazio={{
        name: "",
        contact_name: "",
        email: "",
        phone: "",
        city: "",
        state: "",
        is_active: true,
        material_ids: [],
      }}
      paraFormulario={(f) => ({
        name: f.name,
        contact_name: f.contact_name ?? "",
        email: f.email ?? "",
        phone: f.phone ?? "",
        city: f.city ?? "",
        state: f.state ?? "",
        is_active: f.is_active,

        // `?? []` porque a resposta antiga da API não trazia a relação: uma
        // aba aberta antes do deploy leria `undefined` e o seletor quebraria
        // ao tentar procurar dentro dele.
        material_ids: (f.materials ?? []).map((m) => m.id),
      })}
      campos={({ form, define, errors }) => (
        <>
          <TextField
            label="Empresa"
            name="name"
            required
            value={form.name}
            onChange={(v) => define("name", v)}
            errors={errors}
          />

          <TextField
            label="Pessoa de contato"
            name="contact_name"
            value={form.contact_name}
            onChange={(v) => define("contact_name", v)}
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

          <SeletorDeMateriais
            selecionados={form.material_ids}
            onChange={(ids) => define("material_ids", ids)}
          />

          <div className="flex items-center justify-between rounded-lg border p-3">
            <Label htmlFor="fornecedor-ativo" className="text-sm">
              Fornecedor ativo
            </Label>
            <Switch
              id="fornecedor-ativo"
              checked={form.is_active}
              onCheckedChange={(v) => define("is_active", v)}
            />
          </div>
        </>
      )}
      api={{
        list: api.suppliers.list,
        create: (form) => api.suppliers.create(paraPayload(form)),
        update: (id, form) => api.suppliers.update(id, paraPayload(form)),
        remove: api.suppliers.remove,
      }}
    />
  );
}

function paraPayload(form: Formulario) {
  return {
    ...form,
    contact_name: form.contact_name || null,
    email: form.email || null,
    phone: form.phone || null,
    city: form.city || null,
    state: form.state || null,
  };
}

/**
 * As etiquetas do que o fornecedor vende.
 *
 * Mostra duas e resume o resto. Uma coluna de tabela tem largura fixa: um
 * fornecedor com doze materiais empurraria as linhas vizinhas para fora da tela
 * e faria a lista inteira ficar ilegível por causa de um único registro. Quem
 * precisa da lista completa abre a edição.
 */
function Etiquetas({ materiais }: { materiais: Supplier["materials"] }) {
  const lista = materiais ?? [];

  if (lista.length === 0) {
    return <span className="text-xs text-muted-foreground">—</span>;
  }

  const visiveis = lista.slice(0, 2);
  const restantes = lista.length - visiveis.length;

  return (
    <div className="flex flex-wrap items-center gap-1">
      {visiveis.map((material) => (
        <Badge key={material.id} variant="secondary" className="max-w-40 text-[10px]">
          <span className="min-w-0 truncate">{material.name}</span>
        </Badge>
      ))}

      {restantes > 0 && (
        <span
          className="text-[10px] text-muted-foreground tabular-nums"
          title={lista.map((m) => m.name).join(", ")}
        >
          +{restantes}
        </span>
      )}
    </div>
  );
}

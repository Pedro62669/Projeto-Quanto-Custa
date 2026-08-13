"use client";

import { useEffect, useRef, useState } from "react";
import { ImageOff, Loader2, TriangleAlert, Upload } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { FormError, TextField } from "@/components/form/Field";
import type { FieldErrors } from "@/components/form/Field";
import { PageHeader } from "@/components/PageHeader";
import { ErrorState } from "@/components/data/states";

import { useApi, mensagemDeErro } from "@/hooks/useApi";
import { api, ApiError, type Company } from "@/lib/api";
import { useAccount } from "@/store/useAccount";

/**
 * Os campos que esta tela edita — listados, não deduzidos.
 *
 * O formulário nascia de um spread do que a API devolvesse, tirando `id` e
 * `logo_url`. Bastou o servidor passar a mandar `pendencias_para_pdf` junto do
 * registro para um ARRAY entrar no formulário como se fosse texto, e o
 * `valor.trim()` do envio estourar. O TypeScript não viu: `CompanyPayload`
 * estende `Company`, e campo a mais numa variável (ao contrário de um literal)
 * é atribuição válida.
 *
 * Com a lista explícita, campo novo na API não vira campo no formulário — e o
 * payload sai com exatamente o que esta tela se propõe a alterar.
 */
const CAMPOS = [
  "name",
  "legal_name",
  "document",
  "email",
  "whatsapp",
  "phone",
  "instagram",
  "tiktok",
  "facebook",
  "website",
  "postal_code",
  "street",
  "street_number",
  "complement",
  "district",
  "city",
  "state",
] as const;

/** Tudo string: o formulário não tem número nenhum. */
type Formulario = Record<(typeof CAMPOS)[number], string>;

/**
 * Perfil da empresa.
 *
 * É o cabeçalho de toda proposta em PDF. Sem esta tela, o gerador montava o
 * documento com o que tinha — que era o nome e mais nada —, e toda proposta
 * saía sem CNPJ, sem endereço e sem marca. O aviso de pendências no topo existe
 * para isso ser descoberto AQUI, e não num PDF que já foi para o cliente.
 */
export default function EmpresaPage() {
  const recarregaConta = useAccount((s) => s.reload);

  const empresa = useApi("empresa", () => api.company.get());

  if (empresa.error) {
    return <ErrorState message={empresa.error} onRetry={empresa.refetch} />;
  }

  const pendencias = empresa.data?.pendencias_para_pdf ?? [];

  return (
    <div className="mx-auto max-w-3xl space-y-5">
      <PageHeader
        title="Dados da empresa"
        description="É o cabeçalho de toda proposta em PDF: razão social, CNPJ, endereço, contato e marca."
      />

      {pendencias.length > 0 && (
        <Card className="border-amber-500/50 bg-amber-500/5">
          <CardContent className="flex items-start gap-2 p-3 text-xs">
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-amber-600" />
            <p>
              A proposta em PDF ainda sai incompleta. Falta:{" "}
              <strong className="font-medium">{pendencias.join(", ")}</strong>.
            </p>
          </CardContent>
        </Card>
      )}

      <LogoCard
        temLogo={Boolean(empresa.data?.logo_url)}
        onChange={() => {
          empresa.refetch();
          void recarregaConta();
        }}
      />

      {empresa.data ? (
        <FormularioDaEmpresa
          /*
           * `key` semeia o formulário por REMONTAGEM, em vez de um efeito que
           * copia os dados para o estado. É o caminho que o React pede: estado
           * inicial é estado inicial, e sincronizá-lo por efeito produz uma
           * renderização em cascata a cada resposta do servidor.
           */
          key={`empresa:${empresa.data.id}`}
          inicial={empresa.data}
          onSalvo={() => {
            empresa.refetch();

            // O cabeçalho mostra o nome da empresa: sem recarregar o retrato da
            // sessão, ele exibiria o nome anterior até a próxima visita.
            void recarregaConta();
          }}
        />
      ) : (
        <Skeleton className="h-96 w-full" />
      )}
    </div>
  );
}

function FormularioDaEmpresa({
  inicial,
  onSalvo,
}: {
  inicial: Company;
  onSalvo: () => void;
}) {
  const [form, setForm] = useState<Formulario>(() => paraFormulario(inicial));
  const [errors, setErrors] = useState<FieldErrors>({});
  const [erroGeral, setErroGeral] = useState<string | null>(null);
  const [salvando, setSalvando] = useState(false);

  const define = (campo: keyof Formulario, valor: string) =>
    setForm((atual) => ({ ...atual, [campo]: valor }));

  async function salvar(evento: React.FormEvent) {
    evento.preventDefault();

    setSalvando(true);
    setErrors({});
    setErroGeral(null);

    // Campo vazio vira null: "" não passa nas regras de e-mail e UF do servidor,
    // e "não informado" é null, não string vazia.
    const payload = Object.fromEntries(
      CAMPOS.map((campo) => [campo, form[campo].trim() || null]),
    );

    try {
      await api.company.update(payload);

      toast.success("Dados da empresa salvos");
      onSalvo();
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
        <form onSubmit={salvar} className="space-y-6">
          <FormError message={erroGeral} />

          <Bloco titulo="Identificação">
            <div className="grid gap-3 sm:grid-cols-2">
              <TextField
                label="Nome fantasia"
                name="name"
                required
                value={form.name}
                onChange={(v) => define("name", v)}
                errors={errors}
                hint="É o que aparece no cabeçalho do sistema."
              />
              <TextField
                label="Razão social"
                name="legal_name"
                value={form.legal_name}
                onChange={(v) => define("legal_name", v)}
                errors={errors}
              />
              <TextField
                label="CNPJ ou CPF"
                name="document"
                value={form.document}
                onChange={(v) => define("document", v)}
                errors={errors}
                placeholder="00.000.000/0000-00"
              />
              <TextField
                label="Site"
                name="website"
                value={form.website}
                onChange={(v) => define("website", v)}
                errors={errors}
              />
            </div>
          </Bloco>

          <Bloco titulo="Contato">
            <div className="grid gap-3 sm:grid-cols-3">
              <TextField
                label="WhatsApp"
                name="whatsapp"
                value={form.whatsapp}
                onChange={(v) => define("whatsapp", v)}
                errors={errors}
              />
              <TextField
                label="Telefone"
                name="phone"
                value={form.phone}
                onChange={(v) => define("phone", v)}
                errors={errors}
              />
              <TextField
                label="E-mail"
                name="email"
                type="email"
                value={form.email}
                onChange={(v) => define("email", v)}
                errors={errors}
              />
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
              <TextField
                label="Instagram"
                name="instagram"
                value={form.instagram}
                onChange={(v) => define("instagram", v)}
                errors={errors}
                placeholder="@empresa"
              />
              <TextField
                label="TikTok"
                name="tiktok"
                value={form.tiktok}
                onChange={(v) => define("tiktok", v)}
                errors={errors}
              />
              <TextField
                label="Facebook"
                name="facebook"
                value={form.facebook}
                onChange={(v) => define("facebook", v)}
                errors={errors}
              />
            </div>
          </Bloco>

          <Bloco titulo="Endereço">
            <div className="grid gap-3 sm:grid-cols-4">
              <TextField
                label="CEP"
                name="postal_code"
                value={form.postal_code}
                onChange={(v) => define("postal_code", v)}
                errors={errors}
                maxLength={9}
              />
              <TextField
                label="Rua"
                name="street"
                value={form.street}
                onChange={(v) => define("street", v)}
                errors={errors}
                className="sm:col-span-2"
              />
              <TextField
                label="Número"
                name="street_number"
                value={form.street_number}
                onChange={(v) => define("street_number", v)}
                errors={errors}
              />
            </div>

            <div className="grid gap-3 sm:grid-cols-4">
              <TextField
                label="Complemento"
                name="complement"
                value={form.complement}
                onChange={(v) => define("complement", v)}
                errors={errors}
              />
              <TextField
                label="Bairro"
                name="district"
                value={form.district}
                onChange={(v) => define("district", v)}
                errors={errors}
              />
              <TextField
                label="Cidade"
                name="city"
                value={form.city}
                onChange={(v) => define("city", v)}
                errors={errors}
              />
              <TextField
                label="UF"
                name="state"
                value={form.state}
                onChange={(v) => define("state", v.toUpperCase())}
                errors={errors}
                maxLength={2}
                placeholder="SP"
              />
            </div>
          </Bloco>

          <div className="flex justify-end">
            <Button type="submit" disabled={salvando}>
              {salvando && <Loader2 className="size-4 animate-spin" />}
              Salvar dados
            </Button>
          </div>
        </form>
  );
}

/** Null vira string vazia: o `<input>` controlado não aceita null. */
/** Null vira string vazia: o `<input>` controlado não aceita null. */
function paraFormulario(empresa: Company): Formulario {
  return Object.fromEntries(
    CAMPOS.map((campo) => [campo, empresa[campo] ?? ""]),
  ) as Formulario;
}

function Bloco({ titulo, children }: { titulo: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          {titulo}
        </h2>
        {children}
      </CardContent>
    </Card>
  );
}

/**
 * Logotipo.
 *
 * A imagem vem por requisição autenticada e vira URL de objeto: o arquivo mora
 * num disco privado, e servi-lo publicamente tornaria a lista de clientes do
 * SaaS enumerável por URL. O `<img>` comum não manda o token, então não há
 * atalho aqui.
 */
function LogoCard({
  temLogo,
  onChange,
}: {
  temLogo: boolean;
  onChange: () => void;
}) {
  const [previa, setPrevia] = useState<string | null>(null);
  const [ocupado, setOcupado] = useState(false);
  const entrada = useRef<HTMLInputElement>(null);

  useEffect(() => {
    // Sem logo não há o que buscar — e zerar a prévia aqui seria um setState
    // síncrono no efeito. Quem some com ela é o `previaVisivel` abaixo.
    if (!temLogo) return;

    let url: string | null = null;
    let cancelado = false;

    api.company
      .logoObjectUrl()
      .then((objeto) => {
        if (cancelado) {
          URL.revokeObjectURL(objeto);

          return;
        }

        url = objeto;
        setPrevia(objeto);
      })
      .catch(() => setPrevia(null));

    return () => {
      cancelado = true;

      // Sem o revoke o blob fica retido em memória até a aba fechar.
      if (url) URL.revokeObjectURL(url);
    };
  }, [temLogo]);

  async function enviar(arquivo: File) {
    setOcupado(true);

    try {
      await api.company.uploadLogo(arquivo);
      toast.success("Logotipo atualizado");
      onChange();
    } catch (erro) {
      toast.error(mensagemDeErro(erro));
    } finally {
      setOcupado(false);
      if (entrada.current) entrada.current.value = "";
    }
  }

  async function remover() {
    setOcupado(true);

    try {
      await api.company.removeLogo();
      toast.success("Logotipo removido");
      onChange();
    } catch (erro) {
      toast.error(mensagemDeErro(erro));
    } finally {
      setOcupado(false);
    }
  }

  // Derivado, e não estado: removido o logo, a prévia some no mesmo quadro em
  // que o registro deixa de tê-lo, sem esperar um efeito rodar.
  const previaVisivel = temLogo ? previa : null;

  return (
    <Card>
      <CardContent className="flex flex-wrap items-center gap-4 p-4">
        <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted/30">
          {previaVisivel ? (
            // eslint-disable-next-line @next/next/no-img-element -- URL de objeto (blob:), não passa pelo otimizador de imagem do Next.
            <img src={previaVisivel} alt="Logotipo da empresa" className="max-h-full max-w-full object-contain" />
          ) : (
            <ImageOff className="size-6 text-muted-foreground/50" />
          )}
        </div>

        <div className="min-w-0 flex-1 space-y-1">
          <p className="text-sm font-medium">Logotipo</p>
          <p className="text-xs text-muted-foreground">
            PNG ou JPG, até 2 MB. Aparece no topo de cada proposta — sem ele, o
            PDF sai com um degradê no lugar da marca.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <input
            ref={entrada}
            type="file"
            accept="image/png,image/jpeg"
            className="hidden"
            onChange={(e) => {
              const arquivo = e.target.files?.[0];
              if (arquivo) void enviar(arquivo);
            }}
          />

          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={ocupado}
            onClick={() => entrada.current?.click()}
          >
            {ocupado ? <Loader2 className="size-3.5 animate-spin" /> : <Upload className="size-3.5" />}
            {temLogo ? "Trocar" : "Enviar"}
          </Button>

          {temLogo && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={ocupado}
              onClick={remover}
              className="text-muted-foreground"
            >
              Remover
            </Button>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

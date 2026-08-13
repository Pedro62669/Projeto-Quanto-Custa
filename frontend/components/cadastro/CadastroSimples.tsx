"use client";

import { useState } from "react";
import { Loader2, Pencil, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
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
import { FormError } from "@/components/form/Field";
import type { FieldErrors } from "@/components/form/Field";

import { useApi, mensagemDeErro } from "@/hooks/useApi";
import { useDebounce } from "@/hooks/useDebounce";
import { ApiError, type ListParams, type Page } from "@/lib/api";

/**
 * O esqueleto dos cadastros simples — clientes, fornecedores, produtos.
 *
 * Os três são a mesma tela com campos diferentes: listar, buscar, paginar,
 * criar, editar, excluir. Escrever isso três vezes garantiria três
 * comportamentos ligeiramente diferentes quando a rede falha, e a correção de
 * um bug em apenas um deles.
 *
 * O que varia entra por parâmetro: as colunas, os campos do formulário e os
 * textos. O que não varia — os quatro estados, o debounce da busca, o mapeamento
 * dos erros do servidor para os campos — mora aqui.
 */
export interface CadastroSimplesProps<T, F> {
  titulo: string;
  descricao: string;
  /** Singular, para os botões e diálogos: "cliente", "fornecedor", "produto". */
  substantivo: string;
  colunas: Column<T>[];
  /** Estado inicial do formulário de criação. */
  vazio: F;
  /** Converte o registro em formulário na hora de editar. */
  paraFormulario: (registro: T) => F;
  /** Desenha os campos. Recebe o estado, o setter e os erros do servidor. */
  campos: (props: {
    form: F;
    define: <K extends keyof F>(campo: K, valor: F[K]) => void;
    errors: FieldErrors;
  }) => React.ReactNode;
  api: {
    list: (params?: ListParams) => Promise<Page<T>>;
    create: (payload: F) => Promise<T>;
    update: (id: number, payload: F) => Promise<T>;
    remove: (id: number) => Promise<unknown>;
  };
  chave: string;
  identidade: (registro: T) => number;
  rotulo: (registro: T) => string;
  /** Texto do estado vazio quando não há filtro nenhum. */
  vazioDescricao: string;
}

export function CadastroSimples<T, F>({
  titulo,
  descricao,
  substantivo,
  colunas,
  vazio,
  paraFormulario,
  campos,
  api,
  chave,
  identidade,
  rotulo,
  vazioDescricao,
}: CadastroSimplesProps<T, F>) {
  const [pagina, setPagina] = useState(1);
  const [busca, setBusca] = useState("");
  const [emEdicao, setEmEdicao] = useState<T | null>(null);
  const [formAberto, setFormAberto] = useState(false);
  const [aExcluir, setAExcluir] = useState<T | null>(null);

  const buscaAtrasada = useDebounce(busca, 400);

  const lista = useApi(`${chave}:${pagina}:${buscaAtrasada}`, () =>
    api.list({ page: pagina, search: buscaAtrasada || undefined }),
  );

  const colunasComAcoes: Column<T>[] = [
    ...colunas,
    {
      header: "",
      className: "w-20 text-right",
      render: (registro) => (
        <div className="flex items-center justify-end gap-0.5">
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={`Editar ${rotulo(registro)}`}
            onClick={() => {
              setEmEdicao(registro);
              setFormAberto(true);
            }}
          >
            <Pencil />
          </Button>
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={`Excluir ${rotulo(registro)}`}
            onClick={() => setAExcluir(registro)}
            className="text-muted-foreground hover:text-destructive"
          >
            <Trash2 />
          </Button>
        </div>
      ),
    },
  ];

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <PageHeader
        title={titulo}
        description={descricao}
        actions={
          <Button
            onClick={() => {
              setEmEdicao(null);
              setFormAberto(true);
            }}
          >
            <Plus className="size-4" />
            Novo {substantivo}
          </Button>
        }
      />

      <Input
        placeholder="Buscar…"
        value={busca}
        onChange={(e) => {
          setBusca(e.target.value);

          // Filtro novo começa da primeira página: seguir na página 4 de um
          // filtro que agora tem uma página mostraria lista vazia.
          setPagina(1);
        }}
        className="max-w-xs"
        aria-label={`Buscar ${substantivo}`}
      />

      <DataTable
        columns={colunasComAcoes}
        rows={lista.data?.items ?? []}
        loading={lista.loading}
        error={lista.error}
        onRetry={lista.refetch}
        rowKey={identidade}
        empty={
          busca ? (
            <EmptyState
              title="Nada encontrado"
              description="Limpe a busca para ver a lista inteira."
            />
          ) : (
            <EmptyState title={`Nenhum ${substantivo} cadastrado`} description={vazioDescricao} />
          )
        }
      />

      <Pagination page={lista.data} onChange={setPagina} />

      {formAberto && (
        <FormularioSheet
          // Remonta a cada registro: sem isso, "editar" logo depois de "novo"
          // reaproveitaria o estado anterior e mostraria os campos errados.
          key={emEdicao ? `edicao:${identidade(emEdicao)}` : "novo"}
          aberto={formAberto}
          onOpenChange={setFormAberto}
          titulo={emEdicao ? `Editar ${substantivo}` : `Novo ${substantivo}`}
          inicial={emEdicao ? paraFormulario(emEdicao) : vazio}
          campos={campos}
          onSalvar={async (form) => {
            if (emEdicao) {
              await api.update(identidade(emEdicao), form);
            } else {
              await api.create(form);
            }

            lista.refetch();
          }}
        />
      )}

      <AlertDialog
        open={aExcluir !== null}
        onOpenChange={(aberto) => !aberto && setAExcluir(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Excluir {aExcluir ? rotulo(aExcluir) : ""}?
            </AlertDialogTitle>
            <AlertDialogDescription>
              O registro sai da lista. Se ele estiver ligado a orçamentos ou
              lançamentos, o servidor recusa a exclusão e explica o porquê.
            </AlertDialogDescription>
          </AlertDialogHeader>

          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              onClick={async () => {
                if (!aExcluir) return;

                try {
                  await api.remove(identidade(aExcluir));
                  toast.success("Registro excluído");
                  lista.refetch();
                } catch (erro) {
                  toast.error(mensagemDeErro(erro));
                } finally {
                  setAExcluir(null);
                }
              }}
            >
              Excluir
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

function FormularioSheet<F>({
  aberto,
  onOpenChange,
  titulo,
  inicial,
  campos,
  onSalvar,
}: {
  aberto: boolean;
  onOpenChange: (aberto: boolean) => void;
  titulo: string;
  inicial: F;
  campos: (props: {
    form: F;
    define: <K extends keyof F>(campo: K, valor: F[K]) => void;
    errors: FieldErrors;
  }) => React.ReactNode;
  onSalvar: (form: F) => Promise<void>;
}) {
  const [form, setForm] = useState<F>(inicial);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [erroGeral, setErroGeral] = useState<string | null>(null);
  const [salvando, setSalvando] = useState(false);

  const define = <K extends keyof F>(campo: K, valor: F[K]) =>
    setForm((atual) => ({ ...atual, [campo]: valor }));

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();

    setSalvando(true);
    setErrors({});
    setErroGeral(null);

    try {
      await onSalvar(form);
      toast.success("Registro salvo");
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
            <SheetTitle>{titulo}</SheetTitle>
            <SheetDescription>
              Os campos obrigatórios são validados pelo servidor.
            </SheetDescription>
          </SheetHeader>

          <SheetBody className="space-y-4">
            <FormError message={erroGeral} />
            {campos({ form, define, errors })}
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

/** Selo de ativo/inativo, repetido nos três cadastros. */
export function SeloAtivo({ ativo }: { ativo: boolean }) {
  if (ativo) return null;

  return (
    <Badge variant="outline" className="text-[10px]">
      inativo
    </Badge>
  );
}

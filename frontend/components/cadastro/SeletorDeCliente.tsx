"use client";

import { useMemo, useState } from "react";
import { Check, Loader2, UserPlus } from "lucide-react";

import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useApi } from "@/hooks/useApi";
import { api, type Client } from "@/lib/api";
import { cn } from "@/lib/utils";

/**
 * "Para quem é este orçamento?"
 *
 * Antes daqui o campo era texto livre, e o cadastro de clientes não servia para
 * nada: "Papelaria Silva" digitada três vezes virava três clientes, e nenhum
 * deles acumulava histórico. Ligar o orçamento ao cadastro é o que faz a ficha
 * do cliente existir.
 *
 * Mas texto livre continua valendo. Muita venda de cartonagem fecha com um nome
 * e um WhatsApp, e obrigar o cadastro completo antes de orçar travaria o caminho
 * mais comum — então digitar um nome que não está na lista é um resultado
 * legítimo, não um erro a corrigir.
 *
 * A busca é no CLIENTE, sobre a lista já carregada, e não uma requisição por
 * tecla: são dezenas de clientes, não milhares, e o servidor já devolve todos.
 * Um debounce contra a API aqui gastaria rede para reordenar vinte linhas.
 */
export function SeletorDeCliente({
  clienteId,
  nome,
  onChange,
}: {
  clienteId: number | null;
  nome: string;
  onChange: (escolha: { clienteId: number | null; nome: string }) => void;
}) {
  const clientes = useApi("clientes:seletor", () =>
    api.clients.list({ per_page: 100 }),
  );

  /*
   * O que está escrito no campo, que NÃO é o mesmo que o nome escolhido.
   *
   * Enquanto a pessoa digita "sil" à procura de "Papelaria Silva", o nome
   * escolhido ainda é o anterior. Colar os dois faria cada tecla apagar a
   * seleção — e escolher um cliente e continuar digitando é o gesto normal de
   * quem se enganou na busca.
   */
  const [busca, setBusca] = useState("");

  /*
   * O `?? []` mora DENTRO do memo de propósito.
   *
   * Fora dele, o literal vazio seria um array novo a cada render e o memo
   * recalcularia sempre — filtrando a lista inteira a cada tecla digitada em
   * qualquer campo do diálogo, não só neste.
   */
  const itens = clientes.data?.items;

  const encontrados = useMemo(() => {
    const lista = itens ?? [];
    const alvo = busca.trim().toLowerCase();

    if (!alvo) return lista;

    return lista.filter((c) => c.name.toLowerCase().includes(alvo));
  }, [itens, busca]);

  /** Digitou um nome que não é de ninguém da lista: vale como cliente avulso. */
  const avulso = busca.trim();
  const jaExiste = encontrados.some(
    (c) => c.name.toLowerCase() === avulso.toLowerCase(),
  );

  function escolher(cliente: Client) {
    onChange({ clienteId: cliente.id, nome: cliente.name });
    setBusca("");
  }

  return (
    <div className="space-y-2">
      <Label htmlFor="busca-cliente">
        Cliente<span className="text-destructive"> *</span>
      </Label>

      <Input
        id="busca-cliente"
        placeholder={nome || "Busque ou digite um nome"}
        value={busca}
        onChange={(e) => setBusca(e.target.value)}
        autoComplete="off"
      />

      {/* O escolhido, sempre visível. Sem isto, limpar a busca deixaria a tela
          sem dizer para quem o orçamento vai — e o botão de salvar habilitado. */}
      {nome && (
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
          <Check className="size-3.5 text-primary" />
          {clienteId === null ? (
            <>
              <span className="font-medium text-foreground">{nome}</span>
              <span>· avulso, sem cadastro</span>
            </>
          ) : (
            <span className="font-medium text-foreground">{nome}</span>
          )}
        </p>
      )}

      {clientes.loading && (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 className="size-3.5 animate-spin" />
          Carregando clientes…
        </p>
      )}

      {clientes.error && (
        <p className="text-xs text-destructive">
          Não foi possível carregar os clientes. Digite o nome — o orçamento pode
          ser salvo assim mesmo.
        </p>
      )}

      {busca.trim() !== "" && (
        <div className="max-h-44 space-y-0.5 overflow-y-auto rounded-lg border p-1">
          {encontrados.map((cliente) => (
            <button
              key={cliente.id}
              type="button"
              onClick={() => escolher(cliente)}
              className={cn(
                "flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted",
                cliente.id === clienteId && "bg-muted",
              )}
            >
              <span className="min-w-0 flex-1 truncate">{cliente.name}</span>
              {cliente.city && (
                <span className="shrink-0 text-xs text-muted-foreground">
                  {cliente.city}
                </span>
              )}
            </button>
          ))}

          {/* Usar o que foi digitado. Fica ABAIXO dos encontrados de propósito:
              quem procura um cadastro deve topar com ele antes de criar uma
              segunda versão do mesmo cliente por engano. */}
          {avulso !== "" && !jaExiste && (
            <button
              type="button"
              onClick={() => {
                onChange({ clienteId: null, nome: avulso });
                setBusca("");
              }}
              className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted"
            >
              <UserPlus className="size-3.5 shrink-0 text-muted-foreground" />
              <span className="min-w-0 truncate">
                Usar <span className="font-medium">{avulso}</span> sem cadastrar
              </span>
            </button>
          )}
        </div>
      )}
    </div>
  );
}

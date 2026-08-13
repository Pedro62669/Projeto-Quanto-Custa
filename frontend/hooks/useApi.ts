"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import { ApiError } from "@/lib/api";

/**
 * Leitura da API com os quatro estados que toda tela precisa.
 *
 * Carregando, erro, vazio e conteúdo — os quatro, e não só o último. Uma tela
 * que só trata "deu certo" mostra uma tabela vazia quando a rede falha, e o
 * usuário conclui que não tem dado nenhum cadastrado.
 *
 * A CHAVE é o que dispara a recarga, e é ela em vez de um array de dependências
 * por uma razão prática: o `carrega` é quase sempre uma arrow criada durante a
 * renderização, então entraria nas dependências como um valor novo a cada
 * quadro e produziria um laço infinito de requisições. A chave é uma string
 * estável que o chamador monta com o que de fato muda ("clientes:2:ativos").
 *
 * `loading` é DERIVADO — a resposta guardada carrega a chave que a produziu, e
 * está carregando enquanto essa chave não for a atual. Chamar `setLoading(true)`
 * no corpo do efeito produziria uma renderização em cascata a cada busca, e o
 * compilador do React recusa com razão.
 *
 * Não é cache: cada montagem busca de novo. O estado do servidor não é
 * espelhado em store nenhuma — a única store do sistema é a da calculadora,
 * que tem estado local de verdade.
 */
export function useApi<T>(chave: string, carrega: () => Promise<T>) {
  const [gatilho, setGatilho] = useState(0);
  const [resposta, setResposta] = useState<{
    chave: string;
    data: T | null;
    error: string | null;
  } | null>(null);

  // A busca de agora é identificada por chave + tentativa: `refetch()` não muda
  // a chave, mas precisa contar como uma busca nova.
  const buscaAtual = `${chave}#${gatilho}`;

  // Guarda a versão mais recente do carregador sem colocá-la nas dependências.
  const carregaRef = useRef(carrega);
  useEffect(() => {
    carregaRef.current = carrega;
  });

  useEffect(() => {
    let cancelado = false;

    carregaRef
      .current()
      .then((resultado) => {
        // A resposta de uma busca antiga não pode sobrescrever a tela: trocar
        // de página duas vezes rápido deixaria a segunda lista na tela e a
        // primeira nos dados.
        if (cancelado) return;

        setResposta({ chave: buscaAtual, data: resultado, error: null });
      })
      .catch((erro: unknown) => {
        if (cancelado) return;

        setResposta({ chave: buscaAtual, data: null, error: mensagemDeErro(erro) });
      });

    return () => {
      cancelado = true;
    };
  }, [buscaAtual]);

  const atualizada = resposta?.chave === buscaAtual;

  return {
    /*
     * Enquanto a próxima página carrega, os dados da anterior CONTINUAM na
     * tela. Zerá-los faria a tabela piscar em branco a cada clique — e o vazio
     * momentâneo é indistinguível de "não há registros".
     */
    data: resposta?.data ?? null,
    loading: !atualizada,
    error: atualizada ? resposta.error : null,
    refetch: useCallback(() => setGatilho((g) => g + 1), []),
  };
}

/**
 * A mensagem que o usuário lê.
 *
 * Erro de validação (422) chega com os campos em `errors` e uma mensagem geral
 * pouco útil ("The given data was invalid"). Nesses casos vale mais a primeira
 * mensagem de campo — é ela que diz o que corrigir.
 */
export function mensagemDeErro(erro: unknown): string {
  if (erro instanceof ApiError) {
    const primeiroCampo = Object.values(erro.errors)[0]?.[0];

    return primeiroCampo ?? erro.message;
  }

  if (erro instanceof Error) return erro.message;

  return "Não foi possível completar a operação.";
}

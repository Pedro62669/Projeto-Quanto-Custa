"use client";

import { useEffect, useState } from "react";

/**
 * O valor, atrasado.
 *
 * Usado nos campos de busca que disparam consulta paginada: sem o atraso, cada
 * tecla vira uma requisição, e as respostas voltam fora de ordem — a lista de
 * "ma" chegando depois da de "maria" e sobrescrevendo-a.
 *
 * O `setValor` acontece dentro do timer, não no corpo do efeito: é uma
 * atualização assíncrona vinda de um sistema externo (o relógio), que é
 * exatamente o que um efeito deve fazer.
 */
export function useDebounce<T>(valor: T, atrasoMs = 400): T {
  const [atrasado, setAtrasado] = useState(valor);

  useEffect(() => {
    const timer = setTimeout(() => setAtrasado(valor), atrasoMs);

    return () => clearTimeout(timer);
  }, [valor, atrasoMs]);

  return atrasado;
}

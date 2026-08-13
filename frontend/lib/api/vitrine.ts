import type { Vitrine } from "./types";

/**
 * A tabela de preços da página pública, buscada NO SERVIDOR.
 *
 * Não passa pelo `request()` do resto do sistema, e a diferença é deliberada:
 * aquele cliente lê o token do localStorage e derruba a sessão no 401 — coisas
 * que só existem no navegador. Aqui a chamada acontece durante a renderização em
 * Node, para quem ainda não tem sessão nenhuma.
 *
 * `revalidate` de uma hora: o preço fica no HTML que chega ao visitante e ao
 * buscador (uma tabela de preços carregada por JavaScript depois não é indexada
 * e ainda pisca na tela), mas continua vindo do servidor — mudar a mensalidade
 * no enum PHP atualiza o site sozinho dentro de uma hora, sem novo build.
 */
const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api";

export async function buscarVitrine(): Promise<Vitrine | null> {
  try {
    const resposta = await fetch(`${API_URL}/plans`, {
      headers: { Accept: "application/json" },
      next: { revalidate: 3600 },
    });

    if (!resposta.ok) return null;

    const corpo = (await resposta.json()) as { data: Vitrine };

    return corpo.data;
  } catch {
    /*
     * Devolve null, e a página trata.
     *
     * A API pode estar fora no momento do build ou da revalidação. Um erro aqui
     * derrubaria a página INTEIRA — a home do produto sairia do ar por causa da
     * seção de preços. Melhor a landing continuar de pé convidando ao cadastro
     * do que o site inteiro responder 500.
     *
     * O que não se faz aqui é cair para uma tabela de preços escrita à mão:
     * seria a segunda verdade que este endpoint existe para evitar, e ela
     * apareceria justamente quando ninguém está olhando.
     */
    return null;
  }
}

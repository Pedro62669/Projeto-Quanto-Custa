import path from "node:path";
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  turbopack: {
    /**
     * Fixa a raiz do projeto neste diretório.
     *
     * O Turbopack infere a raiz procurando um lockfile nos diretórios acima.
     * Como o repositório tem um package.json na raiz (o runner que sobe
     * backend e frontend juntos), ele encontrava o lockfile de lá e passava a
     * resolver módulos contra o node_modules do topo — onde zustand, three e
     * drei não existem, quebrando a aplicação com MODULE_NOT_FOUND.
     *
     * Sem isto, qualquer package.json adicionado acima deste diretório volta a
     * derrubar o frontend de uma forma nada óbvia.
     */
    root: path.resolve(__dirname),
  },

  /**
   * Cache eterno para os pesos de `public/`.
   *
   * Os chunks do próprio Next já saem com `max-age=31536000, immutable` porque
   * o nome deles carrega o hash do conteúdo. O que está em `public/` não: o Next
   * serve tudo ali com `max-age=0`, e o navegador revalida a cada carregamento.
   *
   * Para as malhas 3D isso pesa de verdade: `models/mailer.glb` tem 154 KB.
   * Medido: ida e volta ao servidor em toda visita à calculadora, e o download
   * inteiro de novo a cada limpeza de cache do navegador.
   *
   * `immutable` é seguro aqui porque estes arquivos não mudam. No dia em que um
   * deles precisar mudar, o caminho é RENOMEAR o arquivo, e não editar no lugar:
   * é o mesmo contrato que o hash dos chunks já cumpre sozinho.
   *
   * Limitado a esta pasta de propósito. `public/` também guarda coisas
   * que se troca sem renomear — um favicon, um logo — e cache eterno nelas
   * deixaria o usuário preso à versão antiga por um ano.
   */
  async headers() {
    return [
      {
        source: "/models/:arquivo*",
        headers: [
          {
            key: "Cache-Control",
            value: "public, max-age=31536000, immutable",
          },
        ],
      },
    ];
  },
};

export default nextConfig;

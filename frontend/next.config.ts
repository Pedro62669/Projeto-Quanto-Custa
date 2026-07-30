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
};

export default nextConfig;

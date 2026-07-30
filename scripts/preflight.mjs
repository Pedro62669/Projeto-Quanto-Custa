/**
 * Checagem de portas antes de subir o ambiente de desenvolvimento.
 *
 * Motivo: o `npm run dev` usa --kill-others, então uma porta ocupada derruba os
 * dois servidores de uma vez. A mensagem do concurrently ("Address already in
 * use") não diz QUEM está ocupando nem o que fazer — e o caso mais comum é uma
 * instância anterior que ficou órfã.
 *
 * Este script falha cedo, nomeia o processo e entrega o comando pronto.
 */

import { createServer } from "node:net";
import { execSync } from "node:child_process";

const PORTAS = [
  { porta: 8000, servico: "API (Laravel)" },
  { porta: 3000, servico: "Frontend (Next.js)" },
];

/** Tenta ocupar a porta: se conseguir, está livre. */
function estaLivre(porta) {
  return new Promise((resolve) => {
    const server = createServer();

    server.once("error", (err) => resolve(err.code !== "EADDRINUSE"));
    server.once("listening", () => server.close(() => resolve(true)));

    server.listen(porta, "0.0.0.0");
  });
}

/**
 * Identifica o processo que segura a porta.
 * Best-effort: depende de ss/lsof, então qualquer falha vira "desconhecido"
 * em vez de derrubar o preflight.
 */
function quemOcupa(porta) {
  for (const cmd of [
    `ss -ltnp 2>/dev/null | grep -w ':${porta}'`,
    `lsof -ti tcp:${porta} 2>/dev/null`,
  ]) {
    try {
      const saida = execSync(cmd, { encoding: "utf8" }).trim();
      if (!saida) continue;

      const pid = saida.match(/pid=(\d+)/)?.[1] ?? saida.split("\n")[0].trim();
      if (/^\d+$/.test(pid)) {
        let nome = "";
        try {
          nome = execSync(`ps -p ${pid} -o comm=`, { encoding: "utf8" }).trim();
        } catch {
          /* processo de outro usuário: seguimos só com o PID */
        }
        return { pid, nome };
      }
    } catch {
      /* comando indisponível neste sistema */
    }
  }

  return null;
}

const ocupadas = [];

for (const { porta, servico } of PORTAS) {
  if (!(await estaLivre(porta))) {
    ocupadas.push({ porta, servico, dono: quemOcupa(porta) });
  }
}

if (ocupadas.length === 0) {
  process.exit(0);
}

const RED = "\x1b[31m";
const YELLOW = "\x1b[33m";
const DIM = "\x1b[2m";
const RESET = "\x1b[0m";

console.error(`\n${RED}Porta ocupada — o ambiente não pode subir.${RESET}\n`);

const pids = [];

for (const { porta, servico, dono } of ocupadas) {
  const quem = dono
    ? `${dono.nome || "processo"} (PID ${dono.pid})`
    : "processo desconhecido";

  console.error(`  ${YELLOW}:${porta}${RESET}  ${servico} — em uso por ${quem}`);
  if (dono?.pid) pids.push(dono.pid);
}

console.error(
  `\n${DIM}Quase sempre é uma instância anterior que ficou órfã.${RESET}`,
);

if (pids.length > 0) {
  console.error(`Para liberar:  ${YELLOW}kill ${pids.join(" ")}${RESET}`);
} else {
  console.error(`Para investigar:  ${YELLOW}ss -ltnp | grep -E ':3000|:8000'${RESET}`);
}

console.error("");
process.exit(1);

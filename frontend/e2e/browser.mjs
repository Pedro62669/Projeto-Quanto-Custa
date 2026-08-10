/**
 * Onde os testes de navegador acham um Chrome e onde despejam artefato.
 *
 * Os dois scripts de e2e nasceram com "/usr/bin/google-chrome" cravado e um
 * caminho de scratchpad da máquina de quem escreveu. Funcionava lá e em nenhum
 * outro lugar — no Windows o launch morria antes do primeiro check. Resolver o
 * browser em tempo de execução é o que deixa a mesma suíte rodar nos dois.
 */
import { chromium } from "playwright";
import { existsSync, mkdirSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

/**
 * Candidatos por plataforma, em ordem de preferência.
 *
 * Só famílias Chromium: a rasterização por software abaixo depende das flags
 * ANGLE/SwiftShader, que são do Chromium. Edge e Brave entram porque em máquina
 * Windows é comum não haver Chrome, e ambos aceitam as mesmas flags.
 */
function candidatosDoSistema() {
  const { ProgramFiles, LOCALAPPDATA } = process.env;
  const ProgramFilesX86 = process.env["ProgramFiles(x86)"];

  if (process.platform === "win32") {
    return [
      [ProgramFiles, "Google\\Chrome\\Application\\chrome.exe"],
      [ProgramFilesX86, "Google\\Chrome\\Application\\chrome.exe"],
      [LOCALAPPDATA, "Google\\Chrome\\Application\\chrome.exe"],
      [ProgramFiles, "Microsoft\\Edge\\Application\\msedge.exe"],
      [ProgramFilesX86, "Microsoft\\Edge\\Application\\msedge.exe"],
      [ProgramFiles, "BraveSoftware\\Brave-Browser\\Application\\brave.exe"],
      [ProgramFilesX86, "BraveSoftware\\Brave-Browser\\Application\\brave.exe"],
    ]
      .filter(([raiz]) => Boolean(raiz))
      .map(([raiz, resto]) => join(raiz, resto));
  }

  if (process.platform === "darwin") {
    return [
      "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
      "/Applications/Chromium.app/Contents/MacOS/Chromium",
      "/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge",
    ];
  }

  return [
    "/usr/bin/google-chrome",
    "/usr/bin/google-chrome-stable",
    "/usr/bin/chromium",
    "/usr/bin/chromium-browser",
    "/snap/bin/chromium",
    "/usr/bin/microsoft-edge",
  ];
}

/**
 * Resolve o executável, do mais explícito ao mais adivinhado.
 *
 * O bundle do Playwright vem ANTES do browser do sistema: quando ele existe é a
 * versão que o playwright do projeto foi testado contra. Mas ele costuma não
 * existir (ninguém rodou `playwright install`), e é por isso que a busca no
 * sistema existe em vez de o script simplesmente exigir o download.
 */
function acharExecutavel() {
  if (process.env.E2E_CHROME) {
    if (!existsSync(process.env.E2E_CHROME)) {
      throw new Error(`E2E_CHROME aponta para um arquivo que não existe: ${process.env.E2E_CHROME}`);
    }
    return process.env.E2E_CHROME;
  }

  // executablePath() lança se o Playwright não conhece a plataforma; o caminho
  // devolvido também pode simplesmente não ter sido baixado ainda.
  try {
    const doBundle = chromium.executablePath();
    if (doBundle && existsSync(doBundle)) return doBundle;
  } catch {
    // sem bundle: cai para o browser do sistema
  }

  const encontrado = candidatosDoSistema().find((p) => existsSync(p));
  if (encontrado) return encontrado;

  throw new Error(
    "Nenhum Chrome/Chromium encontrado. Instale um, rode `npx playwright install chromium`, " +
      "ou aponte E2E_CHROME para o executável.",
  );
}

/** Sobe o navegador com rasterização por software — sem ela não há WebGL em headless. */
export async function abrirNavegador() {
  return chromium.launch({
    executablePath: acharExecutavel(),
    args: [
      "--use-gl=angle",
      "--use-angle=swiftshader",
      "--enable-unsafe-swiftshader",
      "--no-sandbox",
    ],
  });
}

/**
 * Pasta de screenshots, criada na hora.
 *
 * Fora do repositório de propósito: são artefato de diagnóstico, não fonte, e
 * despejá-los na árvore só rende sujeira no git status.
 */
export function pastaDeSaida() {
  const destino = process.env.E2E_OUT ?? join(tmpdir(), "quantocusta-e2e");
  mkdirSync(destino, { recursive: true });
  return destino;
}

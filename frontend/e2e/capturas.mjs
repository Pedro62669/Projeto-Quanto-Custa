/**
 * As capturas de tela que a vitrine usa como prova.
 *
 * ## Por que um script, e não arquivos soltos
 *
 * A landing precisa mostrar o produto, e não um desenho inspirado nele — ainda
 * mais desde que o teste grátis saiu: sem cliente para depor e sem versão de
 * avaliação, a única prova disponível É o produto. Uma imagem exportada à mão
 * envelhece na primeira mudança de interface e ninguém percebe, porque nada
 * quebra: a página segue vendendo uma tela que não existe mais.
 *
 * Gerada por comando, a captura é regenerável e conferível. `npm run capturas`
 * com os dois servidores de pé e as imagens da vitrine voltam a ser verdade.
 *
 * ## Tema claro só
 *
 * O `.dark` do globals.css existe em tokens mas nada o ativa — a aplicação não
 * tem alternador de tema. Capturar os dois dobraria peso e manutenção por um
 * caso que hoje não acontece. No dia em que o alternador entrar, este arquivo é
 * o lugar de acrescentar a segunda passada.
 *
 * ## Nunca capture dados reais
 *
 * Roda sobre a empresa semeada pelo InitialDataSeeder. Apontar para um inquilino
 * de verdade publicaria o nome de clientes e os preços dele numa página aberta.
 */
import { abrirNavegador } from "./browser.mjs";
import { mkdirSync } from "node:fs";
import { join } from "node:path";

const BASE = process.env.E2E_BASE_URL ?? "http://localhost:3000";
const API = process.env.E2E_API_URL ?? "http://localhost:8000/api";
const EMAIL = process.env.E2E_EMAIL ?? "admin@quantocusta.local";
const SENHA = process.env.E2E_PASSWORD ?? "admin123";

const DESTINO = join(process.cwd(), "public", "capturas");
mkdirSync(DESTINO, { recursive: true });

/*
 * 1280 × 800 e não 1440 × 900.
 *
 * A imagem é exibida com no máximo ~900 px de largura na página; capturar maior
 * só engorda o arquivo. O `next/image` reamostra na entrega, mas o PNG de origem
 * continua no repositório — e este projeto acabou de tirar 1,5 MB de peso morto
 * da calculadora para não recolocá-lo na vitrine.
 */
const LARGURA = 1280;
const ALTURA = 800;

const browser = await abrirNavegador();
const page = await browser.newPage({ viewport: { width: LARGURA, height: ALTURA } });

const log = (...a) => console.log(...a);

await page.goto(`${BASE}/login`, { waitUntil: "networkidle", timeout: 60000 });
await page.fill("#email", EMAIL);
await page.fill("#password", SENHA);
await page.click('button[type="submit"]');
await page.waitForURL((u) => !u.toString().includes("/login"), { timeout: 60000 });

/**
 * Tira da imagem o que não é produto.
 *
 * A faixa de confirmação de e-mail é estado daquela conta, não do sistema. E o
 * indicador do Next (o círculo escuro no canto) é ferramenta de desenvolvimento
 * — numa captura de página de vendas ele denuncia que a imagem saiu de um
 * ambiente local, que é a única coisa ali que o visitante não deveria ver.
 */
async function limpaCasca() {
  await page.addStyleTag({
    content: `
      [data-slot="app-shell"] > div:first-child:not([data-slot]) { display: none !important; }
      nextjs-portal, [data-nextjs-toast], #__next-build-watcher { display: none !important; }
    `,
  });
}

/**
 * Uma captura.
 *
 * `esperaPor` é um seletor de CONTEÚDO, e vale mais que qualquer tempo fixo: a
 * primeira versão usava só `waitForTimeout` e a aba de hora-empresa saiu como um
 * retângulo cinza — o esqueleto de carregamento, fotografado como se fosse a
 * tela. Um tempo cravado funciona até a máquina estar um pouco mais lenta, e
 * então publica um vazio na página de vendas sem que nada acuse.
 *
 * `alturaMaxima` recorta pelo topo. O plano de corte é uma seção alta que repete
 * o mesmo desenho de folha; o que convence são os primeiros centímetros — a
 * perda orçada contra a real — e uma imagem de 1150 px de altura numa landing
 * vira uma tira que ninguém lê inteira.
 */
async function captura(
  nome,
  rota,
  { espera = 800, seletor = null, antes = null, esperaPor = null, alturaMaxima = null } = {},
) {
  await page.goto(`${BASE}${rota}`, { waitUntil: "networkidle", timeout: 90000 });
  await limpaCasca();
  if (antes) await antes();

  if (esperaPor) {
    await page.waitForSelector(esperaPor, { state: "visible", timeout: 60000 });
  }

  // Tira o foco do último campo tocado: o anel azul e as setinhas do input
  // numérico aparecem na foto e leem como "alguém estava mexendo aqui".
  await page.evaluate(() => document.activeElement?.blur());
  await page.mouse.move(0, 0);

  await page.waitForTimeout(espera);

  const alvo = seletor ? page.locator(seletor).first() : page;

  if (alturaMaxima) {
    const caixa = await alvo.boundingBox();

    /*
     * `fullPage` junto do recorte, e não só o recorte.
     *
     * Sem ele o `clip` é interpretado contra a JANELA, não contra o documento:
     * uma seção que começa abaixo da dobra sai como uma tira de poucos pixels —
     * foi exatamente o que aconteceu com o plano de corte na primeira tentativa.
     */
    await page.screenshot({
      path: join(DESTINO, `${nome}.png`),
      fullPage: true,
      clip: {
        x: caixa.x,
        y: caixa.y,
        width: caixa.width,
        height: Math.min(caixa.height, alturaMaxima),
      },
    });
  } else {
    await alvo.screenshot({ path: join(DESTINO, `${nome}.png`) });
  }

  log(`✓ ${nome}.png  ←  ${rota}`);
}

/*
 * ── A calculadora: o produto ─────────────────────────────────────────────
 *
 * A caixa é MONTADA antes da foto, e não deixada no que estivesse na tela.
 *
 * O padrão é uma RSC de papelão ondulado — correta, e visualmente uma bandeja
 * aberta e achatada. A página vende cartonagem RÍGIDA, e é a caixa com tampa
 * que a pessoa reconhece como o produto dela. Proporção de caixa de presente
 * (200 × 150 × 100) porque é a que se lê como caixa de qualquer ângulo.
 *
 * A espera é maior que nas outras porque o Canvas precisa de alguns quadros
 * para reenquadrar a peça — a foto tirada antes disso pega a caixa fora de
 * escala, cortada pela borda do quadro.
 */
await captura("calculadora", "/calculadora", {
  espera: 6500,
  esperaPor: "canvas",
  antes: async () => {
    await page.selectOption("#box-model", "rigid_telescopic").catch(async () => {
      // O seletor é Radix, não um <select> nativo: cai para o clique.
      await page.click("#box-model");
      await page.getByRole("option", { name: /tampa solta/i }).first().click();
    });
    await page.waitForTimeout(600);

    for (const [id, valor] of [
      ["width", "200"],
      ["height", "100"],
      ["depth", "150"],
    ]) {
      await page.fill(`#${id}`, valor);
    }
  },
});

// ── O plano de corte: o argumento contra a planilha ──────────────────────
const orcamentos = await fetch(`${API}/quotes?per_page=1`, {
  headers: {
    Accept: "application/json",
    Authorization: `Bearer ${await page.evaluate(() => localStorage.getItem("auth_token"))}`,
  },
})
  .then((r) => r.json())
  .catch(() => null);

const primeiro = orcamentos?.data?.[0]?.id;

if (primeiro) {
  /*
   * O PLANO DE CORTE, recortado — não a página inteira.
   *
   * O topo da ficha traz o nome do cliente e a referência do orçamento: dado de
   * uma conta, sem valor de venda e com o inconveniente de expor o que estiver
   * gravado no ambiente. O que convence está mais abaixo: o desenho do arranjo
   * na chapa e a comparação entre a perda ORÇADA e a perda REAL, que é o
   * argumento inteiro contra a planilha numa imagem só.
   */
  await captura("plano-de-corte", `/orcamentos/${primeiro}/ficha-tecnica`, {
    seletor: "section:has-text('Plano de corte')",
    esperaPor: "text=Perda real",
    alturaMaxima: 640,
  });
} else {
  log("… sem orçamento gravado: o plano de corte não foi capturado");
}

/*
 * ── O pôster do herói ────────────────────────────────────────────────────
 *
 * Só o Canvas, recortado da calculadora: é a imagem que a vitrine pinta antes de
 * o 3D existir, e ela precisa ser da MESMA caixa que o `CaixaNoHeroi` monta —
 * senão a troca salta na tela. As medidas estão nos dois lugares e têm de andar
 * juntas (200 × 100 × 150, tampa solta).
 *
 * Recorte 4:3 centrado na peça, que é a proporção da moldura no herói.
 */
await captura("caixa-heroi", "/calculadora", {
  espera: 6500,
  esperaPor: "canvas",
  seletor: "canvas",
  antes: async () => {
    await page.selectOption("#box-model", "rigid_telescopic").catch(async () => {
      await page.click("#box-model");
      await page.getByRole("option", { name: /tampa solta/i }).first().click();
    });
    await page.waitForTimeout(600);

    for (const [id, valor] of [
      ["width", "200"],
      ["height", "100"],
      ["depth", "150"],
    ]) {
      await page.fill(`#${id}`, valor);
    }
  },
});

/*
 * ── A hora-empresa ───────────────────────────────────────────────────────
 *
 * Aqui ficava o painel financeiro, e ele saiu da lista.
 *
 * O motivo é honesto e vale registrar: o banco de desenvolvimento acumula o que
 * as rodadas de e2e deixam para trás, e a captura saiu anunciando "27 parcelas
 * vencidas, R$ 96.939,06 em atraso" numa tarja vermelha. Numa página de vendas
 * isso não mostra um recurso — mostra uma empresa afundada.
 *
 * A aba de hora-empresa não tem esse problema: ela é configuração, não
 * movimento, então não acumula sujeira de teste. E sustenta o argumento mais
 * técnico da página, o de que a sua hora não é o salário dividido por 220.
 */
await captura("hora-empresa", "/custos", {
  esperaPor: "text=Eficiência",
  antes: async () => {
    await page.getByRole("tab", { name: /hora-empresa/i }).click();
  },
});

await browser.close();
log(`\nCapturas em ${DESTINO}`);

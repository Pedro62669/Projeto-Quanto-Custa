/**
 * Smoke test end-to-end no navegador real.
 * Login → calculadora → reatividade do 3D → gravação do orçamento.
 */
import { chromium } from "playwright";

const BASE = process.env.E2E_BASE_URL ?? "http://localhost:3000";

/**
 * Credenciais por variável de ambiente, com o default do InitialDataSeeder.
 *
 * Estavam fixas no código e o teste quebrou assim que a senha do admin foi
 * trocada — um teste de fumaça não pode depender de um dado que muda por fora.
 */
const EMAIL = process.env.E2E_EMAIL ?? "admin@quantocusta.local";
const SENHA = process.env.E2E_PASSWORD ?? "admin123";
const OUT = "/tmp/claude-1000/-var-www-html-quantoCusta/6a279545-fa78-41fb-8b54-46d2abb886a9/scratchpad";

const log = (...a) => console.log(...a);
let failures = 0;
function check(label, ok, detail = "") {
  log(`${ok ? "✓" : "✗"} ${label}${detail ? "  → " + detail : ""}`);
  if (!ok) failures++;
}

// Usa o Chrome do sistema (o bundle do Playwright não está no cache) e força
// rasterização por software, sem a qual não há WebGL em headless.
const browser = await chromium.launch({
  executablePath: "/usr/bin/google-chrome",
  args: ["--use-gl=angle", "--use-angle=swiftshader", "--enable-unsafe-swiftshader", "--no-sandbox"],
});
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

const consoleErrors = [];
page.on("console", (m) => m.type() === "error" && consoleErrors.push(m.text()));
page.on("pageerror", (e) => consoleErrors.push("pageerror: " + e.message));

// ── 1. Guard: rota protegida redireciona para o login ────────────────────
//
// waitUntil "domcontentloaded" e não "networkidle": em modo dev o Next mantém
// o socket do HMR aberto, então a rede nunca fica ociosa e o goto estoura o
// timeout. As esperas explícitas por seletor abaixo são o sinal confiável.
await page.goto(`${BASE}/calculadora`, { waitUntil: "domcontentloaded", timeout: 60000 });
await page.waitForURL("**/login", { timeout: 60000 });
check("guard redireciona para /login", page.url().includes("/login"), page.url());

// ── 2. Login ─────────────────────────────────────────────────────────────
await page.waitForFunction(() => !document.querySelector("#email")?.disabled, null, { timeout: 60000 });
await page.fill("#email", EMAIL);
await page.fill("#password", SENHA);
await page.click('button[type="submit"]');
await page.waitForURL("**/calculadora", { timeout: 60000 });
check("login redireciona para /calculadora", true, page.url());

// ── 3. Bootstrap: formulário e materiais carregados ──────────────────────
await page.waitForSelector("#width", { timeout: 60000 });
const dims = {
  w: await page.inputValue("#width"),
  h: await page.inputValue("#height"),
  d: await page.inputValue("#depth"),
};
check("dimensões iniciais", dims.w === "300" && dims.h === "200" && dims.d === "150", JSON.stringify(dims));

const materialLabel = await page.textContent("#material");
check("material selecionado", Boolean(materialLabel?.trim()), materialLabel?.trim());

// ── 4. Canvas 3D com WebGL ativo ─────────────────────────────────────────
await page.waitForSelector("canvas", { timeout: 20000 });
const canvasInfo = await page.evaluate(() => {
  const c = document.querySelector("canvas");
  return { w: c.width, h: c.height, gl: Boolean(c.getContext("webgl2") || c.getContext("webgl")) };
});
check("canvas 3D renderizado", canvasInfo.w > 0 && canvasInfo.h > 0, `${canvasInfo.w}×${canvasInfo.h}`);

// ── 5. Preço confirmado pelo servidor ────────────────────────────────────
await page.waitForSelector("text=confirmado", { timeout: 20000 });
const precoInicial = await page.textContent(".font-mono.text-4xl");
check("preço confirmado pelo servidor", /R\$/.test(precoInicial ?? ""), precoInicial?.trim());

await page.screenshot({ path: `${OUT}/01-calculadora.png` });

// ── 6. Reatividade: mudar dimensão muda o preço ──────────────────────────
await page.fill("#width", "600");
await page.waitForFunction(
  (antigo) => document.querySelector(".font-mono.text-4xl")?.textContent?.trim() !== antigo,
  precoInicial?.trim(),
  { timeout: 20000 },
);
const precoNovo = await page.textContent(".font-mono.text-4xl");
check("preço reage à mudança de dimensão", precoNovo !== precoInicial, `${precoInicial?.trim()} → ${precoNovo?.trim()}`);

// Largura dobrada => mais material => preço maior.
const num = (s) => parseFloat(s.replace(/[^\d,]/g, "").replace(",", "."));
check("dobrar a largura aumenta o preço", num(precoNovo) > num(precoInicial), `${num(precoInicial)} → ${num(precoNovo)}`);

await page.screenshot({ path: `${OUT}/02-largura-600.png` });

// ── 7. Modo de precificação: markup vs margem ────────────────────────────
const margemMarkup = await page.textContent("text=/de margem real/");
await page.click('button:has-text("Sobre a venda")');
await page.waitForTimeout(1500);
const margemReal = await page.textContent("text=/de margem real/");
check("alternar para modo margem entrega 30% reais", /\b30([.,]0+)?%/.test(margemReal ?? ""), `${margemMarkup?.trim()} → ${margemReal?.trim()}`);

// ── 8. Tampa: renderização e medidas ─────────────────────────────────────
await page.click("#box-model");
await page.click('[role="option"]:has-text("Caixa com tampa")');
await page.waitForTimeout(2500);

const medidasTampa = await page.evaluate(() => {
  const alvo = [...document.querySelectorAll("div")].find((e) =>
    e.textContent?.startsWith("Tampa (L × P × A)"),
  );
  return alvo?.textContent ?? null;
});
check(
  "a bandeja expõe as medidas da tampa",
  /Tampa \(L × P × A\)\s*[\d.]+ × [\d.]+ × [\d.]+ mm/.test(medidasTampa ?? ""),
  medidasTampa,
);

// A tampa encaixa POR FORA: precisa ser mais larga que a base.
const [larguraTampa] = (medidasTampa ?? "").match(/[\d.]+(?= ×)/) ?? [];
check(
  "a tampa é mais larga que a base",
  Number(larguraTampa) > Number(await page.inputValue("#width")),
  `tampa ${larguraTampa} > base ${await page.inputValue("#width")}`,
);

// Digitar a altura da tampa: fixa só esse eixo e encarece a peça.
const precoAutomatico = await page.textContent(".font-mono.text-4xl");
await page.fill("#lid-height", "150");
await page.waitForFunction(
  (antigo) => document.querySelector(".font-mono.text-4xl")?.textContent?.trim() !== antigo,
  precoAutomatico?.trim(),
  { timeout: 20000 },
);
const precoTampaAlta = await page.textContent(".font-mono.text-4xl");
check(
  "tampa mais alta consome mais material e custa mais",
  num(precoTampaAlta) > num(precoAutomatico),
  `${precoAutomatico?.trim()} → ${precoTampaAlta?.trim()}`,
);

// Largura e profundidade continuam automáticas — só a altura foi fixada.
const larguraAposAltura = await page.inputValue("#lid-width");
await page.fill("#width", "400");
await page.waitForTimeout(1500);
check(
  "os eixos não fixados continuam acompanhando a caixa",
  (await page.inputValue("#lid-width")) !== larguraAposAltura &&
    (await page.inputValue("#lid-height")) === "150",
  `largura ${larguraAposAltura} → ${await page.inputValue("#lid-width")}, altura fixa em ${await page.inputValue("#lid-height")}`,
);

// Botão "Automático" devolve todos os eixos ao cálculo derivado.
await page.click('button:has-text("Automático")');
await page.waitForTimeout(1500);
check(
  "restaurar automático desfaz as medidas manuais",
  (await page.inputValue("#lid-height")) !== "150",
  `altura voltou para ${await page.inputValue("#lid-height")}`,
);

// Os modelos sem tampa não podem exibir a linha.
await page.click("#box-model");
await page.click('[role="option"]:has-text("Saco / envelope")');
await page.waitForTimeout(2000);
const semTampa = await page.evaluate(() =>
  [...document.querySelectorAll("div")].some((e) =>
    e.textContent?.startsWith("Tampa (L × P × A)"),
  ),
);
check("modelo sem tampa não mostra a linha", semTampa === false);

// ── Embalagem cilíndrica ─────────────────────────────────────────────────
await page.click("#box-model");
await page.click('[role="option"]:has-text("Tubo")');
await page.waitForTimeout(2500);

// Um cilindro não tem profundidade: o campo precisa sumir e o rótulo mudar.
const rotulos = await page.evaluate(() =>
  [...document.querySelectorAll("label")].map((l) => l.textContent),
);
check(
  "cilindro troca Largura por Diâmetro e esconde Profundidade",
  rotulos.includes("Diâmetro") && !rotulos.includes("Profundidade"),
  rotulos.filter((r) => ["Diâmetro", "Largura", "Profundidade", "Altura"].includes(r)).join(", "),
);

// A tampa do tubo é circular: um único eixo de diâmetro.
const tampaTubo = await page.evaluate(() => {
  const alvo = [...document.querySelectorAll("div")].find((e) =>
    e.textContent?.startsWith("Tampa (Ø × A)"),
  );
  return alvo?.textContent ?? null;
});
check("a tampa do tubo é circular", /Tampa \(Ø × A\)Ø[\d.]+ × [\d.]+ mm/.test(tampaTubo ?? ""), tampaTubo);

// Mudar o diâmetro tem que mover o preço — prova que o motor usa a largura
// como diâmetro em vez de ignorá-la.
// Dobra o valor ATUAL em vez de cravar um número: os passos anteriores já
// mexeram na largura, e um valor fixo poderia significar uma redução.
const diametroAtual = Number(await page.inputValue("#width"));
const precoTubo = await page.textContent(".font-mono.text-4xl");
await page.fill("#width", String(diametroAtual * 2));
await page.waitForFunction(
  (antigo) => document.querySelector(".font-mono.text-4xl")?.textContent?.trim() !== antigo,
  precoTubo?.trim(),
  { timeout: 20000 },
);
const precoTuboLargo = await page.textContent(".font-mono.text-4xl");
check(
  "aumentar o diâmetro encarece o tubo",
  num(precoTuboLargo) > num(precoTubo),
  `Ø${diametroAtual} → Ø${diametroAtual * 2}: ${precoTubo?.trim()} → ${precoTuboLargo?.trim()}`,
);

/** Lê a "Área por unidade" do painel de resultados, em m². */
const areaPorUnidade = () =>
  page.evaluate(() => {
    const alvo = [...document.querySelectorAll("div")].find((e) =>
      e.textContent?.startsWith("Área por unidade"),
    );
    return parseFloat(alvo?.textContent?.match(/[\d.]+(?= m²)/)?.[0] ?? "0");
  });

// ── Caixa gaveta ─────────────────────────────────────────────────────────
//
// Troca o modelo ANTES de medir: o passo anterior deixou o tubo selecionado,
// e no cilindro o campo de profundidade nem existe.
await page.click("#box-model");
await page.click('[role="option"]:has-text("Caixa gaveta")');
await page.waitForTimeout(2500);

// Dimensões explícitas: os passos anteriores deixaram a caixa larga e funda,
// e nessa proporção as abas do RSC (meia profundidade cada) dominam o blank a
// ponto de o RSC gastar MAIS que a gaveta. "Duas peças custam mais" só vale
// em proporções típicas — a comparação abaixo precisa de terreno conhecido.
await page.fill("#width", "200");
await page.fill("#height", "100");
await page.fill("#depth", "150");
await page.waitForTimeout(2500);

// Duas peças: precisa consumir mais material que um RSC das mesmas medidas.
const areaGaveta = await areaPorUnidade();

await page.click("#box-model");
await page.click('[role="option"]:has-text("Caixa americana")');
await page.waitForTimeout(2500);

const areaRsc = await areaPorUnidade();

check(
  "a gaveta (luva + gaveta) consome mais que um RSC",
  areaGaveta > areaRsc && areaRsc > 0,
  `gaveta ${areaGaveta} m² > rsc ${areaRsc} m²`,
);

// ── Slider de abertura ───────────────────────────────────────────────────
//
// Volta para a gaveta: o RSC não tem peça móvel, e é justamente a ausência
// dele que o último check deste bloco verifica.
await page.click("#box-model");
await page.click('[role="option"]:has-text("Caixa gaveta")');
await page.waitForTimeout(2500);

const slider = page.locator('[role="group"][aria-label^="Abertura"] [role="slider"]');

check("a gaveta expõe o controle de abertura", (await slider.count()) === 1);

/*
 * O check que importa: mover o slider não pode falar com o servidor.
 *
 * A abertura é estado de câmera, não de especificação. Comparar só o preço
 * seria um teste fraco — ela não entra em fórmula nenhuma, então o número
 * ficaria igual mesmo que ela vazasse para a store do orçamento. O que
 * denuncia o vazamento é a chamada de simulação: com a abertura na store,
 * cada arrasto sujaria a especificação e dispararia um recálculo por quadro.
 */
let simulacoes = 0;
const contarSimulacoes = (req) => {
  if (req.url().includes("/quotes/simulate")) simulacoes++;
};
page.on("request", contarSimulacoes);

const precoAberta = await page.textContent(".font-mono.text-4xl");
await slider.focus();
await page.keyboard.press("Home"); // vai direto para 0% (fechada)
await page.waitForTimeout(2000);
const precoFechada = await page.textContent(".font-mono.text-4xl");

page.off("request", contarSimulacoes);

check(
  "fechar a caixa não dispara recálculo no servidor",
  simulacoes === 0,
  `${simulacoes} chamada(s) a /quotes/simulate`,
);

check(
  "abrir e fechar a caixa não altera o preço",
  precoAberta?.trim() === precoFechada?.trim() && num(precoAberta) > 0,
  `${precoAberta?.trim()} = ${precoFechada?.trim()}`,
);

/*
 * Controle positivo do instrumento acima.
 *
 * Sem ele, um seletor de URL errado faria o check anterior passar por nunca
 * contar nada — um teste que não pode falhar não protege nada. Uma mudança de
 * ESPECIFICAÇÃO, ao contrário da abertura, tem que falar com o servidor.
 */
simulacoes = 0;
page.on("request", contarSimulacoes);
await page.fill("#height", "130");
await page.waitForTimeout(2500);
page.off("request", contarSimulacoes);

check(
  "mudar uma medida, essa sim, recalcula no servidor",
  simulacoes > 0,
  `${simulacoes} chamada(s) a /quotes/simulate`,
);

// Volta ao RSC para o restante do fluxo.
await page.click("#box-model");
await page.click('[role="option"]:has-text("Caixa americana")');
await page.waitForTimeout(2000);

// Um RSC não tem peça móvel: oferecer o controle seria mentir sobre o que ele faz.
check("modelo sem peça móvel esconde o controle", (await slider.count()) === 0);

// ── 9. Salvar orçamento ──────────────────────────────────────────────────
await page.click('button:has-text("Salvar orçamento")');
await page.waitForSelector("#client_name", { timeout: 10000 });
await page.fill("#client_name", "Cliente E2E");
await page.fill("#client_email", "e2e@teste.com");
await page.screenshot({ path: `${OUT}/03-dialog-salvar.png` });
await page.click('button[type="submit"]:has-text("Salvar")');

await page.waitForSelector("text=/ORC-\\d{4}-\\d{6}/", { timeout: 15000 });
const toast = await page.textContent("text=/ORC-\\d{4}-\\d{6}/");
check("orçamento salvo com referência", /ORC-\d{4}-\d{6}/.test(toast ?? ""), toast?.trim());

await page.screenshot({ path: `${OUT}/04-salvo.png` });

// ── 10. Console limpo ────────────────────────────────────────────────────
const relevantes = consoleErrors.filter((e) => !/favicon|DevTools/i.test(e));
check("sem erros de console", relevantes.length === 0, relevantes.slice(0, 3).join(" | "));

await browser.close();
log(`\n${failures === 0 ? "TODOS OS CHECKS PASSARAM" : failures + " CHECK(S) FALHARAM"}`);
process.exit(failures === 0 ? 0 : 1);

/**
 * Sonda geométrica: mede a peça montada no navegador, em vez de olhar foto.
 *
 * Percorre a cena do R3F, pega o bounding box de cada painel em coordenadas de
 * MUNDO e imprime. É como se confere se a barbatana entrou no bolso e se a
 * lingueta caiu na fenda — ângulo de câmera não prova isso.
 */
import { chromium } from "playwright";

const BASE = "http://localhost:3000";
const [W, H, D] = (process.argv[2] ?? "300x80x250").split("x");

const browser = await chromium.launch({
  executablePath: "/usr/bin/google-chrome",
  args: ["--use-gl=angle", "--use-angle=swiftshader", "--enable-unsafe-swiftshader", "--no-sandbox"],
});
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

/*
 * O three.js anuncia cada Scene criada para __THREE_DEVTOOLS__, se existir.
 * Instalar um coletor ANTES da página carregar é o jeito de alcançar a cena
 * sem pendurar nada de depuração no código do app — o R3F v9 não deixa mais
 * o handle no elemento canvas.
 */
await page.addInitScript(() => {
  window.__cenas = [];
  window.__THREE_DEVTOOLS__ = {
    dispatchEvent(e) {
      if (e?.detail?.isScene) window.__cenas.push(e.detail);
    },
  };
});
page.on("pageerror", (e) => console.log("pageerror:", e.message));

await page.goto(`${BASE}/calculadora`, { waitUntil: "domcontentloaded", timeout: 60000 });
await page.waitForURL("**/login", { timeout: 60000 });
await page.waitForFunction(() => !document.querySelector("#email")?.disabled, null, { timeout: 60000 });
await page.fill("#email", "admin@quantocusta.local");
await page.fill("#password", "admin123");
await page.click('button[type="submit"]');
await page.waitForURL("**/calculadora", { timeout: 60000 });
await page.waitForSelector("#width", { timeout: 60000 });

await page.click("#box-model");
await page.click('[role="option"]:has-text("Mailer")');
await page.waitForTimeout(1200);
await page.fill("#width", W);
await page.fill("#height", H);
await page.fill("#depth", D);
await page.waitForTimeout(2500);
await page.waitForSelector("canvas", { timeout: 60000 });

// Fecha a caixa: é fechada que as peças precisam caber umas nas outras.
const slider = page.locator('[role="group"][aria-label^="Abertura"] [role="slider"]');
await slider.focus();
await page.keyboard.press("Home");
await page.waitForTimeout(1500);

const dados = await page.evaluate(() => {
  const cena = (window.__cenas ?? []).at(-1);
  if (!cena) return { erro: "nenhuma cena anunciada ao __THREE_DEVTOOLS__" };

  const caixas = [];
  cena.traverse((o) => {
    if (!o.isMesh || !o.geometry?.attributes?.position) return;
    o.updateWorldMatrix(true, false);
    const g = o.geometry.clone();
    g.applyMatrix4(o.matrixWorld);
    g.computeBoundingBox();
    const b = g.boundingBox;
    if (!o.name) return;
    caixas.push({
      nome: o.name,
      min: [b.min.x, b.min.y, b.min.z].map((v) => +v.toFixed(4)),
      max: [b.max.x, b.max.y, b.max.z].map((v) => +v.toFixed(4)),
    });
    g.dispose();
  });
  return { caixas };
});

if (dados.erro) {
  console.log(dados);
} else {
  const fmt = (v) => v.map((n) => n.toFixed(3).padStart(7)).join(" ");
  for (const c of dados.caixas) console.log(c.nome.padEnd(16), fmt(c.min), " |", fmt(c.max));
}
await browser.close();

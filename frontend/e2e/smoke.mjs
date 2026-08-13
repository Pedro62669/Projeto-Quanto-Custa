/**
 * Smoke test end-to-end no navegador real.
 * Login → calculadora → reatividade do 3D → gravação do orçamento.
 */
import { abrirNavegador, pastaDeSaida } from "./browser.mjs";

const BASE = process.env.E2E_BASE_URL ?? "http://localhost:3000";

/** A API, para conferir a tela contra a fonte do dado — não para substituí-la. */
const API = process.env.E2E_API_URL ?? "http://localhost:8000/api";

/**
 * Credenciais por variável de ambiente, com o default do InitialDataSeeder.
 *
 * Estavam fixas no código e o teste quebrou assim que a senha do admin foi
 * trocada — um teste de fumaça não pode depender de um dado que muda por fora.
 */
const EMAIL = process.env.E2E_EMAIL ?? "admin@quantocusta.local";
const SENHA = process.env.E2E_PASSWORD ?? "admin123";
const OUT = pastaDeSaida();

const log = (...a) => console.log(...a);
let failures = 0;
function check(label, ok, detail = "") {
  log(`${ok ? "✓" : "✗"} ${label}${detail ? "  → " + detail : ""}`);
  if (!ok) failures++;
}

const browser = await abrirNavegador();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

/** Debounce da sincronização (useServerSync). */
const DEBOUNCE_MS = 500;

/**
 * Espera a tela ficar EM DIA com o servidor.
 *
 * Substitui os `waitForTimeout` cravados que este arquivo usava depois de cada
 * mudança de especificação. Eles funcionavam por sorte: bastava o servidor
 * demorar um pouco mais para a leitura sair um passo atrasada, e o check caía
 * comparando o número velho com ele mesmo — ou, pior, passava por acaso.
 *
 * A espera tem duas partes porque o pedido não sai na hora: primeiro o
 * debounce, senão o "não está calculando" seria lido ANTES de a requisição
 * existir; depois o indicador do cabeçalho apagar, que é o sinal de que a
 * resposta chegou e foi aplicada.
 */
async function esperaEmDia() {
  await page.waitForTimeout(DEBOUNCE_MS + 250);

  await page.waitForFunction(
    () => !document.querySelector("header")?.textContent?.includes("calculando"),
    null,
    { timeout: 30000 },
  );

  // Um quadro para o React pintar o resultado que acabou de entrar na store.
  await page.waitForTimeout(150);
}

const consoleErrors = [];
page.on("console", (m) => m.type() === "error" && consoleErrors.push(m.text()));
page.on("pageerror", (e) => consoleErrors.push("pageerror: " + e.message));

// ── 0. A vitrine: a raiz pertence a quem ainda não é cliente ─────────────
//
// waitUntil "domcontentloaded" e não "networkidle": em modo dev o Next mantém
// o socket do HMR aberto, então a rede nunca fica ociosa e o goto estoura o
// timeout. As esperas explícitas por seletor abaixo são o sinal confiável.
await page.goto(`${BASE}/`, { waitUntil: "domcontentloaded", timeout: 60000 });
await page.waitForSelector("h1", { timeout: 60000 });

check(
  "a raiz abre a página de vendas",
  (await page.locator("h1").innerText()).includes("Quanto custa"),
);

/*
 * O preço TEM que vir do servidor.
 *
 * A tela de assinatura já mostrou R$ 99,90 num cartão enquanto o cabeçalho da
 * mesma página dizia R$ 149,90, porque a interface mantinha a própria tabela.
 * Numa página pública o mesmo erro custa mais: a pessoa decide por ele e só
 * descobre a diferença na fatura. Este check compara o que está na tela com o
 * que /api/plans respondeu — se alguém escrever a mensalidade no HTML e o preço
 * mudar no enum PHP, ele acusa.
 */
const precosDoServidor = await fetch(`${API}/plans`).then((r) => r.json());
const textoDosPlanos = await page.locator("#precos").innerText();

check(
  "a tabela de preços vem do servidor",
  precosDoServidor.data.planos
    .filter((p) => p.pago)
    .every((p) =>
      textoDosPlanos.includes(
        p.mensalidade.toLocaleString("pt-BR", { minimumFractionDigits: 2 }),
      ),
    ),
  textoDosPlanos.replace(/\s+/g, " ").slice(0, 90),
);

// Renderizada no SERVIDOR: o preço precisa estar no HTML que o buscador lê, não
// aparecer depois que o JavaScript rodar.
const htmlCru = await fetch(`${BASE}/`).then((r) => r.text());
check(
  "o preço está no HTML entregue, não só depois do JavaScript",
  htmlCru.includes("149,90") && htmlCru.includes("<title>"),
);

// O caminho que a página inteira existe para produzir.
await page.click('a[href="/cadastro"]');
await page.waitForURL("**/cadastro", { timeout: 30000 });
check("a chamada leva ao cadastro", page.url().includes("/cadastro"));

// ── 1. Guard: rota protegida redireciona para o login ────────────────────
await page.goto(`${BASE}/calculadora`, { waitUntil: "domcontentloaded", timeout: 60000 });
await page.waitForURL("**/login", { timeout: 60000 });
check("guard redireciona para /login", page.url().includes("/login"), page.url());

// ── 2. Login ─────────────────────────────────────────────────────────────
await page.waitForFunction(() => !document.querySelector("#email")?.disabled, null, { timeout: 60000 });
await page.fill("#email", EMAIL);
await page.fill("#password", SENHA);
await page.click('button[type="submit"]');

// O login cai no PAINEL, não na calculadora: com doze módulos, a tela inicial é
// o resumo do dia, e a calculadora é uma das saídas dele. O painel mora em
// `/painel` porque a raiz virou a página de vendas — quem chega ao site sem
// conta precisa encontrar o produto, não uma tela que o manda embora.
await page.waitForURL((url) => new URL(url).pathname === "/painel", { timeout: 60000 });
check("login cai no painel inicial", true, page.url());

// ── 2b. A casca: navegação e o resumo do dia ─────────────────────────────
//
// Espera um item de ADMIN, não a `<nav>`: o menu depende do papel, que chega
// pela rede. Esperar só o contêiner leria a lista antes de o servidor dizer
// quem está logado — e foi assim que este check pegou o piscar da barra.
await page.waitForSelector("nav[aria-label='Navegação principal'] a[href='/materiais']", {
  timeout: 30000,
});

const modulos = await page.evaluate(() =>
  [...document.querySelectorAll("nav[aria-label='Navegação principal'] a")].map(
    (a) => a.getAttribute("href"),
  ),
);
check(
  "a barra lateral lista os módulos",
  ["/calculadora", "/orcamentos", "/materiais", "/financeiro", "/custos"].every((rota) =>
    modulos.includes(rota),
  ),
  `${modulos.length} itens`,
);

// Navegar PELO MENU, e não por URL digitada: é o caminho do usuário, e é ele
// que quebra quando uma rota é renomeada só em um dos dois lugares.
await page.click("nav[aria-label='Navegação principal'] a[href='/materiais']");
await page.waitForURL("**/materiais", { timeout: 30000 });
await page.waitForSelector("table, [data-slot='table']", { timeout: 30000 });

const materiaisNaTela = await page.evaluate(
  () => document.querySelectorAll("tbody tr").length,
);
check("materiais carregam do servidor", materiaisNaTela > 0, `${materiaisNaTela} linhas`);

/*
 * Cadastra a medida da folha no material que a calculadora vai usar.
 *
 * Dois ganhos num passo só: exercita o formulário de material de ponta a ponta
 * (era o buraco que motivou esta fase — não havia como cadastrar insumo) e dá
 * ao plano de corte da ficha técnica o dado sem o qual ele não desenha nada.
 *
 * A primeira linha é a mesma que a calculadora seleciona: as duas listas vêm
 * ordenadas por nome do servidor.
 */
await page.click("tbody tr:first-child button[aria-label^='Editar']");
await page.waitForSelector("text=Folha e fibra", { timeout: 20000 });

await page.getByLabel("Largura da folha (mm)").fill("1000");
await page.getByLabel("Comprimento da folha (mm)").fill("800");
await page.screenshot({ path: `${OUT}/07-material-form.png` });
await page.click('button:has-text("Salvar alterações")');

await page.waitForFunction(
  () => document.body.textContent?.includes("1000 × 800") ?? false,
  null,
  { timeout: 20000 },
);
check("o formulário de material grava a medida da folha", true, "1000 × 800 mm");

await page.screenshot({ path: `${OUT}/07-materiais.png`, fullPage: true });

/*
 * Salva o perfil da empresa.
 *
 * O formulário aqui é semeado com o registro que a API devolve, e um campo a
 * mais na resposta (`pendencias_para_pdf`, um array) entrava no estado como se
 * fosse texto — o envio estourava em `valor.trim is not a function`. Nada
 * estático via: `CompanyPayload` estende `Company`, e campo extra numa variável
 * é atribuição válida em TypeScript.
 *
 * Só gravar de verdade denuncia. E é o caminho que faz a proposta em PDF sair
 * com CNPJ e endereço em vez de um cabeçalho vazio.
 */
await page.click("nav[aria-label='Navegação principal'] a[href='/empresa']");
await page.waitForURL("**/empresa", { timeout: 30000 });
await page.waitForSelector("text=Identificação", { timeout: 30000 });

const cidade = `Marília ${Date.now() % 1000}`;
await page.getByLabel("Cidade").fill(cidade);
await page.getByLabel("UF").fill("SP");
await page.click('button:has-text("Salvar dados")');

// O aviso de sucesso é o sinal: um erro em tempo de execução no envio deixaria
// o formulário parado, sem toast e sem gravação.
await page.waitForSelector("text=Dados da empresa salvos", { timeout: 20000 });
check("o perfil da empresa grava sem quebrar", true, `cidade → ${cidade}`);

// ── 2d. Fornecedor ligado ao material que ele vende ──────────────────────
//
// O vínculo é muitos-para-muitos e viaja como lista de ids. Dois riscos, e o
// teste cobre os dois: gravar (o `sync` acontece) e RELER (a resposta traz a
// relação de volta). O segundo é o que quebrou no MaterialResource — um campo
// aceito na escrita e ausente na leitura faz o formulário de edição apagar o
// dado no salvar seguinte, sem erro nenhum na tela.
await page.click("nav[aria-label='Navegação principal'] a[href='/fornecedores']");
await page.waitForURL("**/fornecedores", { timeout: 30000 });
await page.waitForSelector("table, [data-slot='table']", { timeout: 30000 });

const temFornecedor = (await page.locator("tbody tr").count()) > 0;

if (temFornecedor) {
  await page.click("tbody tr:first-child button[aria-label^='Editar']");
  await page.waitForSelector("fieldset [role=checkbox]", { timeout: 20000 });

  const materiaisOferecidos = await page.locator("fieldset [role=checkbox]").count();
  check(
    "o formulário de fornecedor oferece os materiais cadastrados",
    materiaisOferecidos > 0,
    `${materiaisOferecidos} materiais`,
  );

  /*
   * Zera a seleção antes de marcar, e é a diferença entre um teste e uma
   * armadilha.
   *
   * A primeira versão marcava "o primeiro material desmarcado". Passou por
   * quatro execuções e reprovou na quinta, sem que nada no sistema tivesse
   * mudado: os materiais acabaram. O banco de desenvolvimento é o MESMO entre
   * rodadas, então um teste que só acrescenta estado consome o próprio
   * ambiente até não haver mais o que acrescentar.
   *
   * Limpar primeiro deixa o resultado igual na primeira e na centésima vez — e
   * de quebra exercita o desligar, que é o caminho por onde `sync([])` passa.
   */
  const marcadosAntes = page.locator("fieldset [role=checkbox][data-state=checked]");

  for (let i = (await marcadosAntes.count()) - 1; i >= 0; i--) {
    await marcadosAntes.nth(i).click();
  }

  const primeiro = page.locator("fieldset [role=checkbox]").first();
  const nomeMarcado = await primeiro.locator("xpath=following-sibling::label").innerText();
  await primeiro.click();
  await page.click('button[type="submit"]');
  await page.waitForSelector("fieldset [role=checkbox]", { state: "detached", timeout: 20000 });
  await page.waitForTimeout(800);

  /*
   * A coluna mostra duas etiquetas e resume o resto em "+N" — uma tabela não
   * comporta doze nomes sem empurrar as colunas vizinhas para fora da tela.
   * Então o material recém-ligado pode estar visível OU dentro do resumo, cujo
   * `title` guarda a lista inteira. Procurar só no texto visível reprovaria a
   * tela por ela ter feito exatamente o que devia.
   */
  const linha = page.locator("tbody tr:first-child");
  const resumo = linha.locator("[title]");

  const listaCompleta =
    (await linha.innerText()) +
    ((await resumo.count()) > 0 ? await resumo.first().getAttribute("title") : "");

  check(
    "o material vinculado aparece na lista de fornecedores",
    listaCompleta.includes(nomeMarcado),
    nomeMarcado,
  );

  // A volta: reabrir precisa trazer a marcação de volta do servidor.
  await page.click("tbody tr:first-child button[aria-label^='Editar']");
  await page.waitForSelector("fieldset [role=checkbox]", { timeout: 20000 });

  // EXATAMENTE um: a limpeza acima define o estado esperado, então o número
  // pode ser conferido em vez de só verificado como "maior que zero".
  const marcados = await page.locator("fieldset [role=checkbox][data-state=checked]").count();
  check(
    "reabrir o fornecedor traz os materiais marcados",
    marcados === 1,
    `${marcados} marcado(s) — esperado 1`,
  );

  await page.click('button:has-text("Cancelar")');
  await page.waitForTimeout(400);
}

await page.click("nav[aria-label='Navegação principal'] a[href='/calculadora']");
await page.waitForURL("**/calculadora", { timeout: 30000 });

// ── 3. Bootstrap: formulário e materiais carregados ──────────────────────
await page.waitForSelector("#width", { timeout: 60000 });
const dims = {
  w: await page.inputValue("#width"),
  h: await page.inputValue("#height"),
  d: await page.inputValue("#depth"),
};
check("dimensões iniciais", dims.w === "300" && dims.h === "80" && dims.d === "250", JSON.stringify(dims));

const materialLabel = await page.textContent("#material");
check("material selecionado", Boolean(materialLabel?.trim()), materialLabel?.trim());

// ── 4. Canvas 3D com WebGL ativo ─────────────────────────────────────────
//
// Espera o buffer de desenho ser DIMENSIONADO, não só o elemento existir: o
// R3F mede o container por ResizeObserver, e nos primeiros quadros o canvas
// ainda carrega o 300×150 padrão do HTML — que faria o check relatar um
// tamanho que a tela não tem.
await page.waitForSelector("canvas", { timeout: 20000 });
await page.waitForFunction(
  () => (document.querySelector("canvas")?.width ?? 0) > 400,
  null,
  { timeout: 20000 },
);
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

// ── 5b. O painel: três colunas, cabeçalho e composição ───────────────────
//
// A ordem das colunas é verificada pela POSIÇÃO na tela, não pelas classes:
// classe é a intenção, coordenada é o resultado. Uma regra de grid errada
// passaria despercebida por qualquer asserção baseada em `className`.
const colunas = await page.evaluate(() => {
  const x = (sel) => document.querySelector(sel)?.getBoundingClientRect().x ?? -1;
  return {
    decidir: x("#width"),
    verificar: x("canvas"),
    concluir: x(".font-mono.text-4xl"),
  };
});
check(
  "três colunas: decidir, verificar, concluir",
  colunas.decidir < colunas.verificar && colunas.verificar < colunas.concluir,
  JSON.stringify(colunas),
);

/*
 * Campos lado a lado não podem se sobrepor.
 *
 * O gatilho de <Select> do shadcn nasce `w-fit` e cresce com o texto; célula de
 * grid, por sua vez, se recusa a encolher abaixo do conteúdo. Juntos, faziam
 * "Modelo livre (peças medidas)" passar por cima da quantidade. A largura da
 * coluna caiu para 380px neste layout, então o que antes só apertava agora
 * quebra — e sem uma verificação de GEOMETRIA nenhum teste de conteúdo veria.
 */
const parLadoALado = await page.evaluate(() => {
  const r = (sel) => document.querySelector(sel)?.getBoundingClientRect() ?? null;
  const modelo = r("#box-model");
  const quantidade = r("#quantity");

  if (!modelo || !quantidade) return null;

  return {
    ok: Math.round(modelo.right) <= Math.round(quantidade.left),
    modelo: Math.round(modelo.right),
    quantidade: Math.round(quantidade.left),
  };
});
check(
  "modelo e quantidade não se sobrepõem",
  parLadoALado?.ok === true,
  `modelo termina em ${parLadoALado?.modelo}, quantidade começa em ${parLadoALado?.quantidade}`,
);

// O cabeçalho passa a identificar a EMPRESA, não a ferramenta.
const empresa = await page.textContent("header h1");
check(
  "o cabeçalho mostra o nome da empresa",
  Boolean(empresa?.trim()) && empresa?.trim() !== "Calculadora de embalagens",
  empresa?.trim(),
);

// Indicador de sincronia no topo: existe para o celular, onde o preço (e o
// selo dele) ficam fora da tela.
const sincronia = await page.textContent("header");
check(
  "o cabeçalho indica sincronia com o servidor",
  /em dia com o servidor/.test(sincronia ?? ""),
);

/*
 * A barra de composição precisa FECHAR em 100%.
 *
 * É o que separa uma barra informativa de uma decorativa: se as fatias não
 * somam o preço, existe dinheiro numa categoria que a tela não nomeia — e o
 * usuário não tem como saber qual.
 */
const somaDasFatias = await page.evaluate(() => {
  const titulo = [...document.querySelectorAll("h3")].find(
    (h) => h.textContent?.trim() === "Para onde vai o preço",
  );
  if (!titulo) return null;

  return [...titulo.parentElement.querySelectorAll("li")].reduce((soma, li) => {
    const [, valor] = li.textContent.match(/([\d.]+)%\s*$/) ?? [];
    return soma + parseFloat(valor ?? "0");
  }, 0);
});
check(
  "a barra de composição fecha em 100%",
  somaDasFatias !== null && Math.abs(somaDasFatias - 100) < 0.5,
  `${somaDasFatias}%`,
);

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
//
// Espera a margem MUDAR, pelo mesmo motivo do check da tampa do tubo lá
// embaixo: com 1,5s cravados o valor era lido um passo atrasado e o check caía
// sozinho em máquina carregada, mostrando o número velho dos dois lados.
const margemMarkup = await page.textContent("text=/de margem real/");
await page.click('button:has-text("Sobre a venda")');

// Pollar pelo MESMO seletor que faz a leitura, e não por uma condição escrita à
// parte: se a espera olhasse um nó e o check lesse outro, ela poderia dar por
// satisfeita com um valor que o check nem vê.
let margemReal = margemMarkup;
for (let i = 0; i < 40 && margemReal?.trim() === margemMarkup?.trim(); i++) {
  await page.waitForTimeout(500);
  margemReal = await page.textContent("text=/de margem real/");
}
check("alternar para modo margem entrega 30% reais", /\b30([.,]0+)?%/.test(margemReal ?? ""), `${margemMarkup?.trim()} → ${margemReal?.trim()}`);

// ── 8. Tampa: renderização e medidas ─────────────────────────────────────
await page.click("#box-model");
await page.click('[role="option"]:has-text("Caixa com tampa")');
await esperaEmDia();

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
await esperaEmDia();
check(
  "os eixos não fixados continuam acompanhando a caixa",
  (await page.inputValue("#lid-width")) !== larguraAposAltura &&
    (await page.inputValue("#lid-height")) === "150",
  `largura ${larguraAposAltura} → ${await page.inputValue("#lid-width")}, altura fixa em ${await page.inputValue("#lid-height")}`,
);

// Botão "Automático" devolve todos os eixos ao cálculo derivado.
await page.click('button:has-text("Automático")');
await esperaEmDia();
check(
  "restaurar automático desfaz as medidas manuais",
  (await page.inputValue("#lid-height")) !== "150",
  `altura voltou para ${await page.inputValue("#lid-height")}`,
);

// Os modelos sem tampa não podem exibir a linha.
await page.click("#box-model");
await page.click('[role="option"]:has-text("Saco / envelope")');
await esperaEmDia();
const semTampa = await page.evaluate(() =>
  [...document.querySelectorAll("div")].some((e) =>
    e.textContent?.startsWith("Tampa (L × P × A)"),
  ),
);
check("modelo sem tampa não mostra a linha", semTampa === false);

// ── Embalagem cilíndrica ─────────────────────────────────────────────────
await page.click("#box-model");
await page.click('[role="option"]:has-text("Tubo")');
await esperaEmDia();

// Um cilindro não tem profundidade: o campo precisa sumir e o rótulo mudar.
const rotulos = await page.evaluate(() =>
  [...document.querySelectorAll("label")].map((l) => l.textContent),
);
check(
  "cilindro troca Largura por Diâmetro e esconde Profundidade",
  rotulos.includes("Diâmetro") && !rotulos.includes("Profundidade"),
  rotulos.filter((r) => ["Diâmetro", "Largura", "Profundidade", "Altura"].includes(r)).join(", "),
);

/*
 * A tampa do tubo é circular: um único eixo de diâmetro.
 *
 * Espera a linha APARECER em vez de confiar no waitForTimeout acima. O
 * recálculo é debounced, e num dia de máquina carregada os 2,5s não bastavam:
 * o evaluate lia null e o check falhava sem detalhe nenhum — falso negativo
 * que já custou uma investigação de regressão inexistente.
 */
await page
  .waitForFunction(
    () =>
      [...document.querySelectorAll("div")].some((e) =>
        e.textContent?.startsWith("Tampa (Ø × A)"),
      ),
    null,
    { timeout: 20000 },
  )
  .catch(() => {}); // deixa o check falhar com a mensagem dele, não com timeout

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
await esperaEmDia();

// Dimensões explícitas: os passos anteriores deixaram a caixa larga e funda,
// e nessa proporção as abas do RSC (meia profundidade cada) dominam o blank a
// ponto de o RSC gastar MAIS que a gaveta. "Duas peças custam mais" só vale
// em proporções típicas — a comparação abaixo precisa de terreno conhecido.
await page.fill("#width", "200");
await page.fill("#height", "100");
await page.fill("#depth", "150");
await esperaEmDia();

// Duas peças: precisa consumir mais material que um RSC das mesmas medidas.
const areaGaveta = await areaPorUnidade();

await page.click("#box-model");
await page.click('[role="option"]:has-text("Caixa americana")');
await esperaEmDia();

const areaRsc = await areaPorUnidade();

check(
  "a gaveta (luva + gaveta) consome mais que um RSC",
  areaGaveta > areaRsc && areaRsc > 0,
  `gaveta ${areaGaveta} m² > rsc ${areaRsc} m²`,
);

// ── Mailer box ───────────────────────────────────────────────────────────
//
// A caixa continua 200×100×150, a mesma medida usada acima — o RSC já foi
// medido nessa proporção e serve de referência de graça.
await page.click("#box-model");
await page.click('[role="option"]:has-text("Mailer box")');
await esperaEmDia();

const areaMailer = await areaPorUnidade();

/*
 * A lateral ROLADA é a assinatura do modelo: ela sobe, dobra 180° no topo e
 * desce por dentro, então cada milímetro de altura entra duas vezes no blank.
 * Somado à parede frontal, que também rola, a mailer não tem como consumir
 * menos que um RSC das mesmas medidas.
 *
 * Se alguém simplificar o rolo para parede simples, este check cai junto.
 */
check(
  "a mailer (paredes roladas) consome mais que um RSC",
  areaMailer > areaRsc && areaRsc > 0,
  `mailer ${areaMailer} m² > rsc ${areaRsc} m²`,
);

// A tampa é articulada, parte da mesma chapa: não existe peça de tampa a
// dimensionar, e oferecer os campos sugeriria um grau de liberdade que não há.
check(
  "a mailer não expõe campos de tampa",
  (await page.locator("#lid-height").count()) === 0,
);

// ── Slider de abertura ───────────────────────────────────────────────────
//
// Volta para a gaveta, que é o modelo com a peça móvel mais óbvia.
await page.click("#box-model");
await page.click('[role="option"]:has-text("Caixa gaveta")');
await esperaEmDia();

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
await esperaEmDia();
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
await esperaEmDia();
page.off("request", contarSimulacoes);

check(
  "mudar uma medida, essa sim, recalcula no servidor",
  simulacoes > 0,
  `${simulacoes} chamada(s) a /quotes/simulate`,
);

// ── Modelo livre: a caixa sem equação ────────────────────────────────────
//
// É o único modelo em que a especificação não é um punhado de números soltos,
// e sim uma LISTA — e a lista viaja inteira até o servidor. Este trecho existe
// para provar que o payload montado pela store passa na validação da API: um
// campo a mais ou um nome trocado aqui só apareceria como 422 na tela do
// usuário.
await page.click("#box-model");
await page.click('[role="option"]:has-text("Modelo livre")');
await page.waitForSelector("#part-1-width", { timeout: 20000 });
await esperaEmDia();

check(
  "modelo livre troca as dimensões pelo editor de peças",
  (await page.locator("#width").count()) === 0,
  "o campo de largura da caixa sai de cena",
);

// A cena 3D não tem geometria a renderizar: dá lugar ao desenho em escala.
check(
  "a prévia em escala substitui o canvas 3D",
  (await page.locator("canvas").count()) === 0 &&
    (await page.locator('svg[role="img"]').count()) === 1,
);

/*
 * Os três rótulos de medida cabem lado a lado.
 *
 * O <Label> do shadcn é `display:flex`: rótulo e unidade viravam dois itens que
 * não quebram linha, e "COMPRIMENTO (mm)" atravessava por cima de "QTD." nos
 * ~95px de cada coluna. Verificado por COORDENADA porque o texto continuava
 * correto no DOM — só estava impresso um em cima do outro.
 */
const rotulosDasMedidas = await page.evaluate(() => {
  const r = (id) => document.querySelector(`label[for="${id}"]`)?.getBoundingClientRect();

  const largura = r("part-1-width");
  const comprimento = r("part-1-length");
  const quantidade = r("part-1-quantity");

  if (!largura || !comprimento || !quantidade) return null;

  return {
    ok:
      Math.round(largura.right) <= Math.round(comprimento.left) &&
      Math.round(comprimento.right) <= Math.round(quantidade.left),
    limites: [largura, comprimento, quantidade].map(
      (c) => `${Math.round(c.left)}–${Math.round(c.right)}`,
    ),
  };
});
check(
  "os rótulos das medidas não se sobrepõem",
  rotulosDasMedidas?.ok === true,
  rotulosDasMedidas?.limites.join(" | "),
);

// Preço vindo do servidor com a lista de peças no corpo: é o que prova que o
// payload montado pela store passa na validação, e não só que o preview soma.
const precoLivre = await page.textContent(".font-mono.text-4xl");
check(
  "o servidor precifica o modelo livre",
  /R\$/.test(precoLivre ?? "") && num(precoLivre ?? "") > 0,
  precoLivre?.trim(),
);

// Peça maior, preço maior: prova que a medida digitada é a que precifica.
await page.fill("#part-1-width", "400");
await esperaEmDia();
const precoPecaLarga = await page.textContent(".font-mono.text-4xl");
check(
  "dobrar a largura da peça encarece a caixa",
  num(precoPecaLarga) > num(precoLivre ?? ""),
  `${precoLivre?.trim()} → ${precoPecaLarga?.trim()}`,
);

// Uma segunda peça é material a mais na mesma caixa.
await page.click('button:has-text("Adicionar peça")');
await page.waitForSelector("#part-2-width", { timeout: 10000 });
await esperaEmDia();
const precoDuasPecas = await page.textContent(".font-mono.text-4xl");
check(
  "acrescentar uma peça encarece a caixa",
  num(precoDuasPecas) > num(precoPecaLarga),
  `${precoPecaLarga?.trim()} → ${precoDuasPecas?.trim()}`,
);

await page.screenshot({ path: `${OUT}/05-modelo-livre.png` });

/*
 * A luva não tem peça móvel: é uma cinta aberta nas duas pontas, e oferecer um
 * controle de abertura para ela seria um slider que mente sobre o que faz.
 *
 * Este check já mediu o RSC. Deixou de medir quando a caixa de envio entrou
 * com o modelo do Blender: ela ganhou as oito abas e, com elas, algo que
 * realmente se move. Trocar o modelo do check foi mais honesto que afrouxá-lo
 * — a regra continua valendo, só que sobre uma peça que de fato é rígida.
 */
await page.click("#box-model");
await page.click('[role="option"]:has-text("Luva")');
await esperaEmDia();

check("modelo sem peça móvel esconde o controle", (await slider.count()) === 0);

// Volta ao RSC para o restante do fluxo.
await page.click("#box-model");
await page.click('[role="option"]:has-text("Caixa americana")');
await esperaEmDia();

/*
 * A caixa de envio agora abre e fecha.
 *
 * O modelo veio do Blender (caixa-envio/caixa-envio.blend) com as quatro abas
 * de cima e as quatro de baixo. Antes dele o RSC era desenhado como uma caixa
 * aberta sem aba nenhuma — indistinguível de uma bandeja na tela, que é o
 * modelo logo acima dele no seletor.
 */
check("a caixa de envio expõe o controle de abertura", (await slider.count()) === 1);

const precoRscAberta = await page.textContent(".font-mono.text-4xl");
await slider.focus();
await page.keyboard.press("Home");
await esperaEmDia();

check(
  "fechar as abas da caixa de envio não mexe no preço",
  (await page.textContent(".font-mono.text-4xl"))?.trim() === precoRscAberta?.trim(),
  precoRscAberta?.trim(),
);

// Reabre: o restante do fluxo grava o orçamento, e a captura de tela vale mais
// com a caixa mostrando o volume interno.
await slider.focus();
await page.keyboard.press("End");
await esperaEmDia();

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

// ── 9a. O orçamento não some mais ────────────────────────────────────────
//
// O fecho do buraco que motivou esta fase: a calculadora gravava e nada no
// sistema mostrava a proposta de volta. Percorre o caminho inteiro — lista,
// detalhe, ficha técnica — porque é o caminho que o usuário faz.
const referencia = (toast ?? "").match(/ORC-\d{4}-\d{6}/)?.[0] ?? "";

await page.click("nav[aria-label='Navegação principal'] a[href='/orcamentos']");
await page.waitForURL("**/orcamentos", { timeout: 30000 });
await page.waitForSelector("tbody tr", { timeout: 30000 });

const naLista = await page.evaluate(
  (ref) => document.body.textContent?.includes(ref) ?? false,
  referencia,
);
check("o orçamento salvo aparece na lista", naLista, referencia);

await page.screenshot({ path: `${OUT}/08-orcamentos.png`, fullPage: true });

// Abre o detalhe pela própria linha da tabela.
await page.click("tbody tr");
await page.waitForURL(/\/orcamentos\/\d+$/, { timeout: 30000 });
await page.waitForSelector("text=Composição por unidade", { timeout: 30000 });
check("o detalhe do orçamento abre", page.url().includes("/orcamentos/"), page.url());

await page.screenshot({ path: `${OUT}/09-orcamento-detalhe.png`, fullPage: true });

// ── 9b. Ficha técnica: o plano de corte enfim tem tela ───────────────────
await page.click('a:has-text("Ficha técnica")');
await page.waitForURL(/\/ficha-tecnica$/, { timeout: 30000 });
await page.waitForSelector("text=Gabarito de corte", { timeout: 30000 });

const ficha = await page.evaluate(() => ({
  separacao: document.body.textContent?.includes("Lista de separação") ?? false,
  // As folhas desenhadas: é a medida cadastrada no passo dos materiais que
  // permite o servidor arranjar as peças e esta tela transportá-las para o SVG.
  desenho: document.querySelectorAll("figure svg").length,
  pecas: document.querySelectorAll("figure svg rect").length,
  divergencia: document.body.textContent?.includes("Perda real") ?? false,
}));

check(
  "a ficha técnica desenha o plano de corte",
  ficha.separacao && ficha.desenho > 0 && ficha.pecas > 0 && ficha.divergencia,
  `${ficha.desenho} folha(s), ${ficha.pecas} peça(s) desenhada(s)`,
);

await page.screenshot({ path: `${OUT}/10-ficha-tecnica.png`, fullPage: true });

// Volta para a calculadora — o restante dos checks é lá.
await page.click("nav[aria-label='Navegação principal'] a[href='/calculadora']");
await page.waitForURL("**/calculadora", { timeout: 30000 });
await page.waitForSelector("#width", { timeout: 30000 });
await esperaEmDia();

// ── 9c. Margem crítica muda a cor do selo ────────────────────────────────
//
// Zera o lucro e o selo precisa passar de neutro para destrutivo — é o aviso
// de que aquele preço não sustenta um refazimento.
//
// Clica na PONTA ESQUERDA da trilha em vez de focar o thumb e teclar Home: o
// `aria-label` fica na raiz do slider, não no thumb, então o seletor por
// descendente não focava nada, a tecla ia para a página e o valor seguia em
// 30%. O clique na trilha não depende de onde o rótulo mora.
const trilhaDoLucro = page.locator('[aria-label="Percentual de lucro"]');
const caixaDaTrilha = await trilhaDoLucro.boundingBox();
await page.mouse.click(caixaDaTrilha.x + 1, caixaDaTrilha.y + caixaDaTrilha.height / 2);
await esperaEmDia();

const selo = await page.evaluate(() => {
  const el = [...document.querySelectorAll("span")].find((s) =>
    s.textContent?.trim().endsWith("% de margem real"),
  );
  return el ? { texto: el.textContent.trim(), classe: el.className } : null;
});
check(
  "margem crítica acende o selo vermelho",
  Boolean(selo?.classe.includes("text-destructive")),
  `${selo?.texto} · ${selo?.classe}`,
);

// ── 9c. No celular, a ordem se inverte ───────────────────────────────────
//
// Peça, formulário, preço — empilhados nessa ordem. A verificação é por
// coordenada Y pelo mesmo motivo da checagem das colunas: só a posição final
// prova que a regra de ordenação do grid pegou.
await page.setViewportSize({ width: 390, height: 844 });
await page.waitForTimeout(400);

const empilhado = await page.evaluate(() => {
  const y = (sel) => document.querySelector(sel)?.getBoundingClientRect().y ?? -1;
  return {
    verificar: y("canvas"),
    decidir: y("#width"),
    concluir: y(".font-mono.text-4xl"),
  };
});
check(
  "no celular a peça vem primeiro, depois o formulário, depois o preço",
  empilhado.verificar < empilhado.decidir && empilhado.decidir < empilhado.concluir,
  JSON.stringify(empilhado),
);

await page.screenshot({ path: `${OUT}/06-celular.png`, fullPage: true });
await page.setViewportSize({ width: 1440, height: 900 });

// ── 9d. Carga cheia (F5) numa rota autenticada ───────────────────────────
//
// Todo o resto deste arquivo navega pelo MENU, e navegação de cliente não
// passa por hidratação. Só uma carga cheia executa o HTML do servidor contra
// a árvore do cliente — e foi assim que um `return null` dependente do
// localStorage escapou: pré-renderizava vazio e hidratava a casca inteira.
const errosDeHidratacao = [];
const capturaHidratacao = (e) => {
  if (/hydrat/i.test(e.message)) errosDeHidratacao.push(e.message.split("\n")[0]);
};
page.on("pageerror", capturaHidratacao);

await page.goto(`${BASE}/orcamentos`, { waitUntil: "domcontentloaded", timeout: 60000 });
await page.waitForSelector("tbody tr", { timeout: 30000 });
await page.waitForTimeout(1200);
page.off("pageerror", capturaHidratacao);

check(
  "recarregar a página não quebra a hidratação",
  errosDeHidratacao.length === 0,
  errosDeHidratacao[0] ?? "árvore do servidor igual à do cliente",
);

// ── 9e. A vitrine reconhece quem já é cliente ────────────────────────────
//
// Quem já usa o sistema também chega pela raiz — pelo favorito antigo, pelo
// link no e-mail. O cabeçalho troca o "Criar conta" por um atalho ao painel, e
// isso depende de ler o localStorage, que NÃO existe no servidor. É a mesma
// armadilha de hidratação de cima, agora na página mais visitada do site: por
// isso a carga é cheia, e não uma navegação de cliente.
const errosNaVitrine = [];
const capturaVitrine = (e) => {
  if (/hydrat/i.test(e.message)) errosNaVitrine.push(e.message.split("\n")[0]);
};
page.on("pageerror", capturaVitrine);

await page.goto(`${BASE}/`, { waitUntil: "domcontentloaded", timeout: 60000 });
await page.waitForSelector("header a[href='/painel']", { timeout: 30000 });
await page.waitForTimeout(800);
page.off("pageerror", capturaVitrine);

check(
  "a vitrine oferece o painel a quem tem sessão, sem quebrar a hidratação",
  errosNaVitrine.length === 0,
  errosNaVitrine[0] ?? "atalho para /painel no cabeçalho",
);

// ── 10. Console limpo ────────────────────────────────────────────────────
const relevantes = consoleErrors.filter((e) => !/favicon|DevTools/i.test(e));
check("sem erros de console", relevantes.length === 0, relevantes.slice(0, 3).join(" | "));

await browser.close();
log(`\n${failures === 0 ? "TODOS OS CHECKS PASSARAM" : failures + " CHECK(S) FALHARAM"}`);
process.exit(failures === 0 ? 0 : 1);

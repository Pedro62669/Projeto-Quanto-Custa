/**
 * Teste de paridade entre os motores de precificação PHP e TypeScript.
 *
 * O motor existe duas vezes por decisão de performance: o TS calcula o preview
 * instantâneo enquanto o usuário digita, o PHP é a autoridade que grava. O
 * risco dessa duplicação é os dois divergirem em silêncio depois de alguém
 * alterar apenas um lado — e divergência aqui significa mostrar um preço ao
 * cliente e gravar outro.
 *
 * Este script fecha esse buraco: roda o motor TS contra os casos gerados pelo
 * PHP (`php artisan pricing:export-fixtures`) e falha se qualquer campo
 * divergir.
 *
 * Uso: npm run test:parity
 */

import {
  calculateCompanyHour,
  calculatePricing,
  ENGINE_VERSION,
} from "../lib/pricing/engine";
import type {
  CompanyHourBreakdown,
  CompanyHourParams,
  CostSettings,
  Material,
  PricingBreakdown,
  QuoteSpecification,
} from "../lib/pricing/types";
import fixtures from "../lib/pricing/__fixtures__/parity.json";
import hourFixtures from "../lib/pricing/__fixtures__/company-hour.json";

interface ParityCase {
  name: string;
  material: { cost_per_m2: number; thickness_mm: number | null };
  settings: Pick<
    CostSettings,
    | "energy_tariff_per_kwh"
    | "machine_hour_rate"
    | "machine_power_kw"
    | "labor_hour_rate"
    | "overhead_percent"
    | "tax_percent"
  >;
  spec: Omit<QuoteSpecification, "material_id">;
  expected: PricingBreakdown;
}

/**
 * Tolerância zero por padrão.
 *
 * Os dois motores arredondam nos mesmos pontos e com a mesma precisão, então
 * os resultados devem bater bit a bit. Se este número precisar ser afrouxado,
 * é sinal de que as fórmulas divergiram — não de que o teste está rigoroso
 * demais.
 */
const TOLERANCE = 0;

const RED = "\x1b[31m";
const GREEN = "\x1b[32m";
const DIM = "\x1b[2m";
const RESET = "\x1b[0m";

function run(): number {
  const data = fixtures as unknown as {
    engine_version: string;
    cases: ParityCase[];
  };

  // Primeira barreira: as versões precisam bater. Comparar resultados de
  // motores que se declaram diferentes não provaria nada.
  if (data.engine_version !== ENGINE_VERSION) {
    console.error(
      `${RED}✗ Versões divergentes:${RESET} fixture=${data.engine_version} ` +
        `motor TS=${ENGINE_VERSION}\n` +
        `  Regenere o fixture com: php artisan pricing:export-fixtures`,
    );
    return 1;
  }

  let failures = 0;
  let comparisons = 0;

  for (const testCase of data.cases) {
    const material: Material = {
      id: 0,
      name: "fixture",
      type: "cardboard",
      type_label: "Papelão",
      cost_per_m2: testCase.material.cost_per_m2,
      // A estrutura é sempre medida em área nos casos de paridade: peça e bloco
      // entram como componentes, com custo próprio no caso de teste.
      cost_per_piece: null,
      cost_per_m3: null,
      is_area_based: true,
      default_waste_percent: 0,
      thickness_mm: testCase.material.thickness_mm,
      color_hex: "#000000",
      texture_url: null,
      is_active: true,
    };

    const settings: CostSettings = {
      ...testCase.settings,
      default_profit_margin_percent: 0,
      currency: "BRL",
    };

    const actual = calculatePricing({
      spec: { ...testCase.spec, material_id: 0 },
      material,
      settings,
    });

    const diffs: string[] = [];

    for (const [field, expectedValue] of Object.entries(testCase.expected)) {
      const actualValue = actual[field as keyof PricingBreakdown];
      comparisons++;

      /**
       * Nulos são comparados por identidade, não por subtração.
       *
       * Campos como as medidas da tampa são null nos modelos sem tampa, e
       * `Math.abs(null - 5)` produz NaN — que NÃO é maior que a tolerância.
       * Uma divergência null/número passaria despercebida se o comparador
       * fosse só aritmético.
       */
      const umEhNulo = actualValue === null || expectedValue === null;

      const divergiu = umEhNulo
        ? actualValue !== expectedValue
        : Math.abs((actualValue as number) - (expectedValue as number)) > TOLERANCE;

      if (divergiu) {
        const delta =
          umEhNulo || Number.isNaN(Number(actualValue) - Number(expectedValue))
            ? ""
            : ` (Δ ${((actualValue as number) - (expectedValue as number)).toExponential(3)})`;

        diffs.push(`    ${field}: PHP=${expectedValue}  TS=${actualValue}${delta}`);
      }
    }

    if (diffs.length > 0) {
      failures++;
      console.error(`${RED}✗ ${testCase.name}${RESET}`);
      diffs.forEach((d) => console.error(d));
    } else {
      console.log(`${GREEN}✓${RESET} ${testCase.name}`);
    }
  }

  console.log(
    `\n${DIM}${data.cases.length} casos · ${comparisons} campos comparados${RESET}`,
  );

  if (failures > 0) {
    console.error(
      `\n${RED}Paridade quebrada em ${failures} caso(s).${RESET}\n` +
        `Os motores PHP e TS divergiram — o preview mostraria ao usuário um\n` +
        `preço diferente do que o servidor gravaria. Alinhe as duas fórmulas.`,
    );
    return 1;
  }

  console.log(`${GREEN}Paridade PHP ↔ TypeScript confirmada.${RESET}`);
  return 0;
}

/* ────────────────────────────────────────────────────────────────────────────
 * Hora-empresa (Fase 2)
 * ──────────────────────────────────────────────────────────────────────────── */

interface HourParityCase {
  name: string;
  fixed_cost_amounts: number[];
  equipment: { purchase_value: number; useful_life_months: number }[];
  params: CompanyHourParams;
  expected: CompanyHourBreakdown;
}

/**
 * Achata a estrutura aninhada em caminho → valor.
 *
 * O resultado da hora-empresa não é plano como o do preço: tem `cost_base`,
 * `active_scenario` e um array `comparison` com três cenários. Comparar objeto
 * com objeto por igualdade estrutural diria apenas "divergiu"; achatar diz
 * `comparison.2.hour_cost: PHP=66.67 TS=66.66`, que é o que permite corrigir.
 */
function flatten(value: unknown, prefix = ""): Map<string, unknown> {
  const out = new Map<string, unknown>();

  if (value === null || typeof value !== "object") {
    out.set(prefix, value);
    return out;
  }

  for (const [key, inner] of Object.entries(value)) {
    const path = prefix ? `${prefix}.${key}` : key;
    for (const [k, v] of flatten(inner, path)) out.set(k, v);
  }

  return out;
}

function runCompanyHour(): number {
  const data = hourFixtures as unknown as {
    engine_version: string;
    cases: HourParityCase[];
  };

  if (data.engine_version !== ENGINE_VERSION) {
    console.error(
      `${RED}✗ Versões divergentes (hora-empresa):${RESET} fixture=${data.engine_version} ` +
        `motor TS=${ENGINE_VERSION}\n` +
        `  Regenere com: php artisan pricing:export-hour-fixtures`,
    );
    return 1;
  }

  let failures = 0;
  let comparisons = 0;

  for (const testCase of data.cases) {
    const actual = calculateCompanyHour({
      fixedCostAmounts: testCase.fixed_cost_amounts,
      equipment: testCase.equipment,
      params: testCase.params,
    });

    const esperado = flatten(testCase.expected);
    const obtido = flatten(actual);

    const diffs: string[] = [];

    for (const [path, expectedValue] of esperado) {
      const actualValue = obtido.get(path);
      comparisons++;

      const numerico =
        typeof expectedValue === "number" && typeof actualValue === "number";

      const divergiu = numerico
        ? Math.abs(actualValue - expectedValue) > TOLERANCE
        : actualValue !== expectedValue;

      if (divergiu) {
        diffs.push(`    ${path}: PHP=${expectedValue}  TS=${actualValue}`);
      }
    }

    if (diffs.length > 0) {
      failures++;
      console.error(`${RED}✗ hora/${testCase.name}${RESET}`);
      diffs.forEach((d) => console.error(d));
    } else {
      console.log(`${GREEN}✓${RESET} hora/${testCase.name}`);
    }
  }

  console.log(
    `\n${DIM}${data.cases.length} casos de hora-empresa · ${comparisons} campos comparados${RESET}`,
  );

  if (failures > 0) {
    console.error(
      `\n${RED}Paridade da hora-empresa quebrada em ${failures} caso(s).${RESET}\n` +
        `A tela de custos mostraria uma hora e o motor de preço cobraria outra —\n` +
        `e o erro só apareceria meses depois, como margem que nunca fechou.`,
    );
    return 1;
  }

  console.log(`${GREEN}Paridade da hora-empresa confirmada.${RESET}`);
  return 0;
}

// Os dois rodam SEMPRE, mesmo com o primeiro falhando: ver as duas listas de
// divergência de uma vez economiza um ciclo inteiro de correção.
const falhouPreco = run();
console.log("");
const falhouHora = runCompanyHour();

process.exit(falhouPreco || falhouHora);

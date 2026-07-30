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

import { calculatePricing, ENGINE_VERSION } from "../lib/pricing/engine";
import type {
  CostSettings,
  Material,
  PricingBreakdown,
  QuoteSpecification,
} from "../lib/pricing/types";
import fixtures from "../lib/pricing/__fixtures__/parity.json";

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

      if (Math.abs(actualValue - (expectedValue as number)) > TOLERANCE) {
        diffs.push(
          `    ${field}: PHP=${expectedValue}  TS=${actualValue}  ` +
            `(Δ ${(actualValue - (expectedValue as number)).toExponential(3)})`,
        );
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

process.exit(run());

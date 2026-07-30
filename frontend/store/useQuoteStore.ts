import { create } from "zustand";
import { subscribeWithSelector } from "zustand/middleware";
import { api } from "@/lib/api";
import { calculatePricing, ENGINE_VERSION } from "@/lib/pricing/engine";
import type {
  CostSettings,
  Material,
  PricingBreakdown,
  QuoteSpecification,
} from "@/lib/pricing/types";

/**
 * Estado da calculadora.
 *
 * Por que Zustand e não useState: três consumidores distintos precisam do mesmo
 * estado (formulário, painel financeiro e Canvas 3D). Com useState o estado
 * subiria para a página e cada tecla digitada re-renderizaria a árvore inteira
 * — incluindo o Canvas. Com Zustand, cada componente assina só a fatia que usa,
 * e o BoxViewer só re-renderiza quando uma DIMENSÃO muda, não quando o nome do
 * cliente muda.
 */

const DEFAULT_SPEC: QuoteSpecification = {
  material_id: null,
  box_model: "rsc",
  width_mm: 300,
  height_mm: 200,
  depth_mm: 150,
  quantity: 100,
  waste_percent: 10,
  production_minutes_per_unit: 2.5,
  profit_margin_percent: 30,
  pricing_mode: "markup",
};

interface QuoteState {
  // ── Dados de referência (carregados uma vez) ────────────────────────────
  materials: Material[];
  settings: CostSettings | null;
  currency: string;
  isBootstrapping: boolean;

  // ── Especificação editável ──────────────────────────────────────────────
  spec: QuoteSpecification;

  // ── Resultado ───────────────────────────────────────────────────────────
  /** Cálculo local: instantâneo, exibido enquanto o servidor não responde. */
  preview: PricingBreakdown | null;
  /** Cálculo do servidor: a autoridade. */
  confirmed: PricingBreakdown | null;
  isSyncing: boolean;
  error: string | null;
  /** True quando o motor local está desatualizado em relação ao da API. */
  isEngineStale: boolean;

  // ── Ações ───────────────────────────────────────────────────────────────
  bootstrap: () => Promise<void>;
  updateSpec: (patch: Partial<QuoteSpecification>) => void;
  selectMaterial: (materialId: number) => void;
  syncWithServer: () => Promise<void>;
  reset: () => void;
}

/** Requisição em voo, para abortar quando uma nova a substitui. */
let inFlight: AbortController | null = null;

export const useQuoteStore = create<QuoteState>()(
  subscribeWithSelector((set, get) => ({
    materials: [],
    settings: null,
    currency: "BRL",
    isBootstrapping: true,

    spec: DEFAULT_SPEC,

    preview: null,
    confirmed: null,
    isSyncing: false,
    error: null,
    isEngineStale: false,

    /**
     * Carrega materiais e parâmetros em paralelo e já calcula um preview
     * inicial, para que a tela nunca apareça vazia.
     */
    async bootstrap() {
      try {
        const [materials, parameters] = await Promise.all([
          api.materials(),
          api.parameters(),
        ]);

        const firstMaterial = materials[0] ?? null;

        // O backend não expõe os custos fixos ao usuário comum; o preview local
        // usa a fatia pública (impostos/margem) e zera o resto até a primeira
        // resposta do servidor preencher os números reais.
        const settings: CostSettings = {
          energy_tariff_per_kwh: 0,
          machine_hour_rate: 0,
          machine_power_kw: 0,
          labor_hour_rate: 0,
          overhead_percent: 0,
          tax_percent: parameters.tax_percent,
          default_profit_margin_percent: parameters.default_profit_margin_percent,
          currency: parameters.currency,
        };

        set({
          materials,
          settings,
          currency: parameters.currency,
          isBootstrapping: false,
          spec: {
            ...get().spec,
            material_id: firstMaterial?.id ?? null,
            waste_percent: firstMaterial?.default_waste_percent ?? 10,
            profit_margin_percent: parameters.default_profit_margin_percent,
          },
        });

        get().updateSpec({}); // dispara o primeiro preview
        void get().syncWithServer();
      } catch (error) {
        set({
          isBootstrapping: false,
          error: error instanceof Error ? error.message : "Falha ao carregar os parâmetros.",
        });
      }
    },

    /**
     * Aplica a mudança e recalcula o preview LOCAL na hora (sem rede).
     *
     * A sincronização com o servidor não é disparada aqui: quem a agenda, em
     * debounce, é o hook useServerSync na página. Separar as duas coisas
     * mantém esta ação síncrona e trivial de testar.
     */
    updateSpec(patch) {
      const spec = { ...get().spec, ...patch };
      const { materials, settings } = get();
      const material = materials.find((m) => m.id === spec.material_id);

      set({
        spec,
        preview: material && settings ? calculatePricing({ spec, material, settings }) : null,
      });
    },

    /**
     * Trocar de material arrasta consigo o desperdício padrão daquele material
     * — a menos que o usuário já tenha ajustado o valor manualmente, caso em
     * que sobrescrever seria desfazer uma decisão dele.
     */
    selectMaterial(materialId) {
      const { materials, spec } = get();
      const next = materials.find((m) => m.id === materialId);
      const previous = materials.find((m) => m.id === spec.material_id);

      const wasUntouched = !previous || spec.waste_percent === previous.default_waste_percent;

      get().updateSpec({
        material_id: materialId,
        ...(wasUntouched && next ? { waste_percent: next.default_waste_percent } : {}),
      });
    },

    /**
     * Confirma o cálculo no servidor. É a fonte de verdade exibida como
     * resultado final; o preview local serve apenas de ponte visual.
     */
    async syncWithServer() {
      const { spec } = get();
      if (!spec.material_id) return;

      // Cancela a chamada anterior: sem isso, uma resposta lenta pode chegar
      // após uma mais nova e reescrever a tela com valores obsoletos.
      inFlight?.abort();
      inFlight = new AbortController();

      set({ isSyncing: true, error: null });

      try {
        const result = await api.simulate(spec, inFlight.signal);

        set({
          confirmed: result,
          currency: result.currency,
          isSyncing: false,
          // Deploy parcial (frontend antigo + backend novo) faria os dois
          // motores divergirem em silêncio. Melhor avisar.
          isEngineStale: result.engine_version !== ENGINE_VERSION,
        });
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError") return;

        set({
          isSyncing: false,
          error: error instanceof Error ? error.message : "Não foi possível validar o cálculo.",
        });
      }
    },

    reset() {
      inFlight?.abort();
      set({ spec: DEFAULT_SPEC, preview: null, confirmed: null, error: null });
      get().updateSpec({});
    },
  })),
);

/* ── Seletores ─────────────────────────────────────────────────────────────
   Exportados individualmente para que cada componente assine apenas a fatia
   que consome. É o que impede o Canvas 3D de re-renderizar quando um campo
   financeiro muda. */

/**
 * Fatia consumida pelo Canvas 3D.
 *
 * Inclui o modelo da caixa porque ele define quais faces são abertas na
 * renderização — trocar de "bandeja" para "saco" precisa repintar a malha.
 */
export const selectDimensions = (s: QuoteState) => ({
  widthMm: s.spec.width_mm,
  heightMm: s.spec.height_mm,
  depthMm: s.spec.depth_mm,
  boxModel: s.spec.box_model,
});

export const selectActiveMaterial = (s: QuoteState) =>
  s.materials.find((m) => m.id === s.spec.material_id) ?? null;

/** O servidor manda; o preview local cobre a janela até ele responder. */
export const selectResult = (s: QuoteState) => s.confirmed ?? s.preview;

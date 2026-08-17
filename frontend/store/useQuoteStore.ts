import { create } from "zustand";
import { subscribeWithSelector } from "zustand/middleware";
import { api } from "@/lib/api";
import { calculatePricing, ENGINE_VERSION, isFree } from "@/lib/pricing/engine";
import type {
  BoxModel,
  ComponentInput,
  ComponentRole,
  CostSettings,
  CustomPartInput,
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
  // Proporção rasa e quase quadrada: é a medida de e-commerce mais comum e a
  // que faz a caixa aparecer inteira no preview em qualquer modelo. Uma
  // caixa alta e estreita como primeira impressão do 3D dava a peça de perfil.
  width_mm: 300,
  height_mm: 80,
  depth_mm: 250,
  quantity: 100,
  waste_percent: 10,
  production_minutes_per_unit: 2.5,
  profit_margin_percent: 30,
  pricing_mode: "markup",

  // Tampa em modo automático: acompanha as dimensões da base.
  lid_width_mm: null,
  lid_depth_mm: null,
  lid_height_mm: null,
};

/* ── Peças do modelo livre ────────────────────────────────────────────────── */

/** Medida inicial de uma peça nova, em mm. Um quadrado que cabe em qualquer folha. */
const MEDIDA_INICIAL_MM = 200;

/** Teto do backend (SimulateQuoteRequest). Repetido aqui para avisar ANTES do 422. */
export const MAX_PECAS = 60;
const MAX_MEDIDA_MM = 3000;
const MAX_QUANTIDADE = 500;

/**
 * Materiais que podem virar uma peça medida em milímetros.
 *
 * Ferragem e bloco ficam de fora porque não têm custo por m² — o backend
 * RECUSA convertê-los, e oferecê-los aqui seria montar um formulário cujo envio
 * falha. Dentro dos que sobram, a estrutura puxa papelão e o revestimento puxa
 * o resto (papel, tecido).
 *
 * O recuo para a lista inteira é deliberado: uma empresa que forra com papelão
 * fino, ou que ainda não cadastrou papel, continua conseguindo trabalhar. A
 * separação por tipo é uma sugestão de qual material costuma fazer aquele papel,
 * não uma regra sobre o que é possível.
 */
/**
 * Os materiais que podem cumprir cada papel da LISTA.
 *
 * Diferente de `materiaisParaPeca`, que serve ao modelo livre e só aceita
 * material medido em área: aqui ferragem é contada por peça e berço de espuma é
 * comprado em bloco. Oferecer um papelão como ímã produziria uma exceção do
 * próprio Material no servidor — melhor não oferecer.
 *
 * O berço aceita as duas grandezas porque as duas construções existem: espuma é
 * volume, nicho de cartonagem é área. Quem decide é `cradle_type`.
 */
export function materiaisParaPapel(
  materials: Material[],
  role: ComponentInput["role"],
): Material[] {
  if (role === "hardware") {
    return materials.filter((m) => m.cost_per_piece !== null);
  }

  if (role === "cradle") {
    return materials.filter((m) => m.cost_per_m3 !== null || m.cost_per_m2 !== null);
  }

  return materiaisParaPeca(materials, "wrap");
}

export function materiaisParaPeca(
  materials: Material[],
  role: ComponentRole,
): Material[] {
  const comArea = materials.filter((m) => m.is_area_based && m.cost_per_m2 !== null);

  const doPapel = comArea.filter((m) =>
    role === "wrap" ? m.type !== "cardboard" : m.type === "cardboard",
  );

  return doPapel.length > 0 ? doPapel : comArea;
}

/**
 * Identidade da peça no React.
 *
 * `crypto.randomUUID` só existe em contexto seguro; num servidor de
 * desenvolvimento aberto na rede local (http://192.168.x.x) ele é `undefined` e
 * derrubaria a tela ao adicionar a primeira peça.
 */
function novoId(): string {
  return typeof crypto !== "undefined" && typeof crypto.randomUUID === "function"
    ? crypto.randomUUID()
    : `peca-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

/**
 * Inteiro dentro da faixa que a API aceita.
 *
 * Zero passa de propósito — é o campo vazio no meio da digitação, e é
 * `specEstaCompleta()` que segura a requisição até ele virar número.
 */
function limita(valor: number, maximo: number): number {
  if (!Number.isFinite(valor)) return 0;

  return Math.min(Math.max(Math.round(valor), 0), maximo);
}

/** Monta uma peça já resolvida contra o material. Null se não há material elegível. */
function novaPeca(
  materials: Material[],
  role: ComponentRole,
  ordem: number,
): CustomPartInput | null {
  const material = materiaisParaPeca(materials, role)[0];

  if (!material || material.cost_per_m2 === null) return null;

  return {
    id: novoId(),
    name: role === "wrap" ? `Revestimento ${ordem}` : `Peça ${ordem}`,
    material_id: material.id,
    role,

    // Custo e perda descem do MATERIAL, como no servidor. O usuário mede a
    // peça; quanto custa o metro quadrado dela não é decisão do formulário.
    cost_per_m2: material.cost_per_m2,
    waste_percent: material.default_waste_percent,

    width_mm: MEDIDA_INICIAL_MM,
    length_mm: MEDIDA_INICIAL_MM,
    quantity: 1,
  };
}

/**
 * A especificação está completa o bastante para valer uma ida ao servidor?
 *
 * O campo vazio é um estado legítimo enquanto se digita — apagar "200" para
 * escrever "250" passa por zero. Mandar esse zero à API renderia um 422 e um
 * alerta vermelho no meio da digitação, sobre um erro que o usuário está a uma
 * tecla de consertar.
 */
function specEstaCompleta(spec: QuoteSpecification): boolean {
  if (!isFree(spec.box_model)) return true;

  const parts = spec.custom_parts ?? [];

  return (
    parts.length > 0 &&
    parts.every(
      (p) =>
        p.width_mm >= 1 && p.length_mm >= 1 && p.quantity >= 1 && p.name.trim() !== "",
    )
  );
}

/**
 * Preview local, ou null quando o motor recusa a especificação.
 *
 * O motor LANÇA no modelo livre sem peça e no material sem área — e lançar é o
 * comportamento certo, o mesmo do PHP. Aqui a exceção vira ausência de preview:
 * a tela fica sem número em vez de mostrar um que não descreve nada.
 */
/**
 * Traduz a lista de materiais para o que o MOTOR consome.
 *
 * As duas formas existem porque servem a públicos diferentes. `components` é o
 * que a API aceita e o que o orçamento grava: identidade (`material_id`) e
 * papel. O motor não sabe o que é um material — ele quer números já resolvidos:
 * R$/m² do revestimento, R$/peça de cada ferragem, R$/m³ do berço.
 *
 * A tradução acontece AQUI e numa direção só, de identidade para custo, que é a
 * mesma direção do servidor (`QuoteController::resolveComponents`). Guardar as
 * duas formas no estado criaria duas verdades: mexer numa e esquecer a outra
 * daria um preview que não bate com o preço gravado.
 *
 * Componente cujo material sumiu do cadastro é IGNORADO, não zerado — zero é um
 * custo, e um custo de zero precifica o ímã como se fosse de graça.
 */
function paraOMotor(
  components: ComponentInput[],
  materiais: Material[],
  spec: QuoteSpecification,
): Pick<QuoteSpecification, "wrap_cost_per_m2" | "hardware" | "cradle"> {
  const acha = (id: number) => materiais.find((m) => m.id === id);

  const wrap = components.find((c) => c.role === "wrap");
  const cradle = components.find((c) => c.role === "cradle");
  const cradleMaterial = cradle ? acha(cradle.material_id) : undefined;

  return {
    wrap_cost_per_m2: wrap ? (acha(wrap.material_id)?.cost_per_m2 ?? null) : null,

    hardware: components.flatMap((c) => {
      if (c.role !== "hardware") return [];

      const custo = acha(c.material_id)?.cost_per_piece;
      if (custo == null) return [];

      return [{ cost_per_piece: custo, quantity: c.quantity ?? 1 }];
    }),

    /*
     * O berço só existe com TIPO. Sem ele o servidor também devolve null (ver
     * `resolveCradle`), e inventar um padrão aqui faria o preview cobrar um
     * berço que a gravação não cobraria.
     */
    cradle:
      cradle && cradleMaterial && spec.cradle_type
        ? {
            type: spec.cradle_type,

            // Volume ou área conforme o tipo, como no servidor: espuma é bloco,
            // cartonagem é chapa.
            cost_per_unit:
              (spec.cradle_type === "foam"
                ? cradleMaterial.cost_per_m3
                : cradleMaterial.cost_per_m2) ?? 0,

            rows: spec.cradle_rows ?? 1,
            columns: spec.cradle_columns ?? 1,
            height_ratio: spec.cradle_height_ratio ?? 1,

            // A espessura da tira sai do MATERIAL, não do formulário: quem
            // define a largura da ranhura é o papelão escolhido.
            strip_thickness_mm: cradleMaterial.thickness_mm ?? 0,
          }
        : null,
  };
}

function calculaPreview(
  spec: QuoteSpecification,
  material: Material | undefined,
  settings: CostSettings | null,
): PricingBreakdown | null {
  if (!material || !settings) return null;

  try {
    return calculatePricing({ spec, material, settings });
  } catch {
    return null;
  }
}

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

  /** Reabre um orçamento gravado — duplicar e editar rascunho. */
  carregarDe: (gravado: Partial<QuoteSpecification>) => void;

  /**
   * Troca de modelo. Existe como ação própria porque mudar para o modelo livre
   * não é só gravar um valor: sem peça alguma o motor recusa a especificação, e
   * a tela abriria vazia e sem preço.
   */
  selectBoxModel: (model: BoxModel) => void;

  // ── Lista de materiais: revestimento, ferragem, berço ───────────────────
  addComponent: (role: ComponentInput["role"]) => void;
  removeComponent: (id: string) => void;
  updateComponent: (id: string, patch: Partial<ComponentInput>) => void;

  // ── Peças do modelo livre ───────────────────────────────────────────────
  addCustomPart: (role?: ComponentRole) => void;
  removeCustomPart: (id: string) => void;
  updateCustomPart: (id: string, patch: Partial<CustomPartInput>) => void;
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
      // Uma nova tentativa não pode herdar o erro da anterior: com ele na tela,
      // o botão "tentar de novo" pareceria não ter feito nada.
      set({ isBootstrapping: true, error: null });

      try {
        const [materials, parameters] = await Promise.all([
          api.pricing.materials(),
          api.pricing.parameters(),
        ]);

        /*
         * O primeiro material que SERVE DE ESTRUTURA, não o primeiro da lista.
         *
         * A API ordena por nome, e a estrutura é cortada em milímetros: um ímã
         * chamado "Ima de neodímio" vinha antes de "Papelão" e virava a
         * matéria-prima padrão. O motor recusa converter peça em área, então a
         * calculadora abria com 422 e sem preço — bastava a empresa cadastrar
         * uma ferragem com nome no começo do alfabeto.
         */
        const firstMaterial = materiaisParaPeca(materials, "structure")[0] ?? null;

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

        /*
         * Os padrões só se aplicam na PRIMEIRA carga.
         *
         * Com navegação no sistema, esta função roda a cada volta para a
         * calculadora — e sobrescrever a especificação aqui apagaria a escolha
         * do usuário toda vez que ele fosse conferir um material e voltasse.
         * O material continua sendo relido do servidor; o que se preserva é a
         * decisão de quem está trabalhando.
         */
        const primeiraCarga = get().spec.material_id === null;

        set({
          materials,
          settings,
          currency: parameters.currency,
          isBootstrapping: false,
          spec: primeiraCarga
            ? {
                ...get().spec,
                material_id: firstMaterial?.id ?? null,
                waste_percent: firstMaterial?.default_waste_percent ?? 10,
                profit_margin_percent: parameters.default_profit_margin_percent,
              }
            : get().spec,
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
      const bruto = { ...get().spec, ...patch };
      const { materials, settings } = get();

      /*
       * Os campos do motor são DERIVADOS a cada mudança, e não guardados.
       *
       * Fazer isso aqui, no único ponto por onde toda alteração passa, é o que
       * garante que trocar um ímã na lista e ver o preço mudar sejam a mesma
       * ação. Recalcular só no componente que edita a lista deixaria o preview
       * correto ali e velho em qualquer outro caminho — reabrir um orçamento,
       * por exemplo.
       */
      const spec = {
        ...bruto,
        ...paraOMotor(bruto.components ?? [], materials, bruto),
      };

      const material = materials.find((m) => m.id === spec.material_id);

      set({ spec, preview: calculaPreview(spec, material, settings) });
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
     * Troca o modelo, semeando a primeira peça quando ele é o livre.
     *
     * A peça nasce junto porque o modelo livre sem peça não é um estado de
     * trabalho: é a única especificação que o motor recusa nas duas pontas. Uma
     * peça pronta para editar diz o que a tela espera do usuário melhor que
     * qualquer texto de ajuda.
     *
     * Ao voltar para um modelo com geometria, as peças PERMANECEM guardadas: a
     * API as ignora fora do modelo livre, e quem experimentou e voltou não perde
     * o que digitou.
     */
    selectBoxModel(model) {
      const { materials, spec } = get();

      const precisaSemear = isFree(model) && (spec.custom_parts ?? []).length === 0;
      const primeira = precisaSemear ? novaPeca(materials, "structure", 1) : null;

      get().updateSpec({
        box_model: model,
        ...(primeira ? { custom_parts: [primeira] } : {}),
      });
    },

    /**
     * Acrescenta um material à lista.
     *
     * Revestimento e berço são ÚNICOS: a peça tem uma pele e um berço, e o
     * servidor já trata o segundo revestimento como troca de seleção, não como
     * duas camadas. Substituir aqui é o que faz a tela concordar com isso —
     * duas linhas de revestimento mostrariam dois custos onde só um seria
     * cobrado.
     */
    addComponent(role) {
      const { materials, spec } = get();
      const atuais = spec.components ?? [];

      const material = materiaisParaPapel(materials, role)[0];

      // Sem material compatível não há linha possível. O formulário explica o
      // que falta; o botão não inventa uma linha inválida.
      if (!material) return;

      const nova: ComponentInput = {
        id: novoId(),
        material_id: material.id,
        role,
        quantity: role === "hardware" ? 1 : null,
      };

      const semOsUnicos =
        role === "hardware" ? atuais : atuais.filter((c) => c.role !== role);

      get().updateSpec({ components: [...semOsUnicos, nova] });
    },

    removeComponent(id) {
      const atuais = get().spec.components ?? [];

      get().updateSpec({ components: atuais.filter((c) => c.id !== id) });
    },

    updateComponent(id, patch) {
      const atuais = get().spec.components ?? [];

      get().updateSpec({
        components: atuais.map((c) => (c.id === id ? { ...c, ...patch } : c)),
      });
    },

    addCustomPart(role = "structure") {
      const { materials, spec } = get();
      const parts = spec.custom_parts ?? [];

      if (parts.length >= MAX_PECAS) return;

      const peca = novaPeca(materials, role, parts.length + 1);

      // Sem material medido em área não há peça possível. O formulário mostra o
      // que fazer; o botão simplesmente não inventa uma linha inválida.
      if (!peca) return;

      get().updateSpec({ custom_parts: [...parts, peca] });
    },

    /**
     * Remove a peça, exceto a última.
     *
     * A lista vazia derrubaria o preview e faria a API responder 422 — e o botão
     * que produz esse estado é um botão que só serve para quebrar a tela. Trocar
     * de modelo é o caminho para sair do modelo livre.
     */
    removeCustomPart(id) {
      const parts = get().spec.custom_parts ?? [];

      if (parts.length <= 1) return;

      get().updateSpec({ custom_parts: parts.filter((p) => p.id !== id) });
    },

    /**
     * Aplica a alteração e RE-RESOLVE o material quando ele muda.
     *
     * Custo e perda não são campos do formulário: eles descem do material, aqui
     * como no servidor. Deixá-los para trás faria a peça continuar sendo
     * precificada pelo material anterior — divergindo do que a API devolveria,
     * que é exatamente a classe de erro que o preview local existe para evitar.
     */
    updateCustomPart(id, patch) {
      const { materials, spec } = get();

      const parts = (spec.custom_parts ?? []).map((part) => {
        if (part.id !== id) return part;

        const proxima: CustomPartInput = {
          ...part,
          ...patch,

          // Limites do backend aplicados na origem: o número inválido nunca
          // chega a existir no estado, então não há como ele viajar.
          ...(patch.width_mm !== undefined
            ? { width_mm: limita(patch.width_mm, MAX_MEDIDA_MM) }
            : {}),
          ...(patch.length_mm !== undefined
            ? { length_mm: limita(patch.length_mm, MAX_MEDIDA_MM) }
            : {}),
          ...(patch.quantity !== undefined
            ? { quantity: limita(patch.quantity, MAX_QUANTIDADE) }
            : {}),
        };

        /*
         * Mudar o PAPEL pode invalidar o material: papelão não costuma forrar.
         * Quando o material atual não serve ao papel novo, a peça migra para o
         * primeiro que serve, em vez de ficar num par incoerente.
         */
        const elegiveis = materiaisParaPeca(materials, proxima.role);

        const material =
          materials.find((m) => m.id === proxima.material_id) ?? undefined;

        const destino =
          patch.role !== undefined && !elegiveis.some((m) => m.id === proxima.material_id)
            ? elegiveis[0]
            : patch.material_id !== undefined
              ? material
              : undefined;

        if (destino && destino.cost_per_m2 !== null) {
          proxima.material_id = destino.id;
          proxima.cost_per_m2 = destino.cost_per_m2;
          proxima.waste_percent = destino.default_waste_percent;
        }

        return proxima;
      });

      get().updateSpec({ custom_parts: parts });
    },

    /**
     * Confirma o cálculo no servidor. É a fonte de verdade exibida como
     * resultado final; o preview local serve apenas de ponte visual.
     */
    async syncWithServer() {
      const { spec } = get();
      if (!spec.material_id) return;

      /*
       * Especificação incompleta não vai à rede.
       *
       * `confirmed` é zerado junto: ele descreve uma especificação que não é
       * mais esta, e mantê-lo na tela mostraria como "confirmado" um preço de
       * medidas que o usuário já mudou.
       */
      if (!specEstaCompleta(spec)) {
        inFlight?.abort();
        set({ confirmed: null, isSyncing: false });

        return;
      }

      // Cancela a chamada anterior: sem isso, uma resposta lenta pode chegar
      // após uma mais nova e reescrever a tela com valores obsoletos.
      inFlight?.abort();
      inFlight = new AbortController();

      set({ isSyncing: true, error: null });

      try {
        const result = await api.pricing.simulate(spec, inFlight.signal);

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

    /**
     * Reabre um orçamento gravado na calculadora — duplicar e editar rascunho.
     *
     * Parte de DEFAULT_SPEC e não do que está na tela: reabrir um orçamento
     * deve dar a caixa daquele orçamento, e herdar campos da simulação anterior
     * produziria uma terceira caixa que não é nem uma nem outra.
     *
     * A lista de materiais volta junto — `updateSpec` no fim deriva os campos
     * do motor a partir dela, do mesmo jeito que faria se a pessoa tivesse
     * digitado. Não há caminho de reabertura separado do caminho de edição.
     */
    carregarDe(gravado) {
      inFlight?.abort();

      /*
       * `set` e não `updateSpec`, como em `reset()`.
       *
       * `updateSpec` MESCLA com o que está na tela, e a mescla não apaga chave
       * ausente: duplicar um RSC logo depois de mexer no modelo livre carregaria
       * as peças antigas junto, e o preço sairia com material que a caixa nova
       * não tem.
       */
      set({
        spec: { ...DEFAULT_SPEC, ...gravado },
        preview: null,
        confirmed: null,
        error: null,
      });

      // Recalcula o preview local para a tela não abrir sem preço.
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

  // As medidas da tampa também redesenham a malha.
  lidWidthMm: s.spec.lid_width_mm,
  lidDepthMm: s.spec.lid_depth_mm,
  lidHeightMm: s.spec.lid_height_mm,
});

export const selectActiveMaterial = (s: QuoteState) =>
  s.materials.find((m) => m.id === s.spec.material_id) ?? null;

/** As peças do modelo livre. Sempre um array, para o consumidor não checar null. */
/**
 * O vazio, uma vez só.
 *
 * Um `?? []` dentro de seletor devolve um array NOVO a cada chamada, e o
 * `useShallow` compara por identidade: o componente nunca vê dois resultados
 * iguais e re-renderiza para sempre. É literalmente o erro que o React reporta
 * como "getSnapshot should be cached" seguido de "Maximum update depth
 * exceeded", e foi assim que a lista de materiais derrubou a calculadora na
 * primeira vez que subiu.
 *
 * `selectCustomParts` tinha a mesma armadilha e escapava por sorte: o modelo
 * livre semeia uma peça ao ser escolhido, então a chave nunca ficava ausente.
 */
const SEM_ITENS: never[] = [];

export const selectCustomParts = (s: QuoteState): CustomPartInput[] =>
  s.spec.custom_parts ?? SEM_ITENS;

export const selectComponents = (s: QuoteState): ComponentInput[] =>
  s.spec.components ?? SEM_ITENS;

export const selectIsFreeModel = (s: QuoteState) => isFree(s.spec.box_model);

/**
 * O carregamento inicial falhou.
 *
 * Distinguido do erro de sincronização pela ausência de `settings`: sem os
 * parâmetros não existe calculadora, e a tela precisa oferecer uma nova
 * tentativa em vez de um formulário vazio. Um erro de simulação, esse, deixa a
 * tela utilizável e aparece no painel financeiro.
 */
export const selectBootstrapFailed = (s: QuoteState) =>
  !s.isBootstrapping && s.settings === null && s.error !== null;

/** O servidor manda; o preview local cobre a janela até ele responder. */
export const selectResult = (s: QuoteState) => s.confirmed ?? s.preview;

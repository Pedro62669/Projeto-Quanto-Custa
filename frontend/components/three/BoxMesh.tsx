"use client";

import { useRef, useMemo, Suspense, type ReactNode } from "react";
import { useFrame } from "@react-three/fiber";
import { Edges, useTexture } from "@react-three/drei";
import * as THREE from "three";
import type { BoxModel } from "@/lib/pricing/types";
import { resolveLidDimensions, isCylindrical, hasSeparateLid } from "@/lib/pricing/engine";

/**
 * Escala da cena: a MAIOR aresta da embalagem sempre ocupa este número de
 * unidades do mundo 3D. É o que permite renderizar uma caixa de joia (40mm) e
 * um container de mudança (1200mm) com a mesma câmera, sem reenquadrar.
 */
const SCENE_MAX_UNITS = 2;

/** Velocidade da interpolação — maior = mais responsivo, menor = mais suave. */
const LERP_SPEED = 8;

/**
 * Espessura mínima de parede, em unidades de cena.
 *
 * Papel de 0,4mm numa caixa de 300mm daria uma parede de 0,0027 unidades —
 * invisível na tela e sujeita a z-fighting. Este piso garante que a peça
 * continue LEGÍVEL como caixa oca, mesmo com materiais finíssimos.
 */
const MIN_WALL_UNITS = 0.022;

/**
 * Distância entre a base e a tampa, em fração da altura da base.
 *
 * A tampa é desenhada FLUTUANDO acima — vista explodida. Encaixada, ela
 * esconderia o interior da caixa e o usuário perderia justamente o que a
 * renderização oca veio mostrar. Separada, comunica que são duas peças.
 */
const LID_GAP_RATIO = 0.28;

export interface BoxDimensions {
  /** Largura (frente) em milímetros. */
  widthMm: number;
  /** Altura em milímetros. */
  heightMm: number;
  /** Profundidade (lateral) em milímetros. */
  depthMm: number;
}

export interface BoxMeshProps extends BoxDimensions {
  /** Modelo da embalagem — define quais faces são abertas. */
  boxModel?: BoxModel;
  /** Espessura do material em mm; vira a espessura visível das paredes. */
  thicknessMm?: number | null;
  /** Cor do material, em hexadecimal (vem do cadastro da matéria-prima). */
  colorHex?: string;
  /** Textura opcional; se ausente ou se falhar o carregamento, usa-se a cor. */
  textureUrl?: string | null;
  /** Destaca os vincos/dobras da planificação. */
  showEdges?: boolean;

  /**
   * Abertura da peça móvel, de 0 (fechada) a 1 (totalmente aberta).
   *
   * É estado de VISUALIZAÇÃO: não entra na especificação do orçamento, não
   * altera o consumo de material e não é persistido. Abrir a caixa na tela
   * não pode mudar o preço.
   */
  aperture?: number;

  /** Medidas da tampa informadas pelo usuário; null em um eixo = automático. */
  lidWidthMm?: number | null;
  lidDepthMm?: number | null;
  lidHeightMm?: number | null;
}

/**
 * Quais faces cada modelo tem abertas.
 *
 * A geometria segue o domínio: um saco é fechado, uma luva é um tubo aberto
 * nas duas pontas, e uma bandeja é justamente a caixa SEM TAMPA — aberta em
 * cima. O RSC aparece com as abas superiores abertas porque o que interessa
 * ao usuário é enxergar o volume interno que vai embalar o produto.
 */
const APERTURAS: Record<BoxModel, { topo: boolean; fundo: boolean }> = {
  rsc: { topo: true, fundo: false },
  tray: { topo: true, fundo: false },
  sleeve: { topo: true, fundo: true },
  pouch: { topo: false, fundo: false },
  // O tubo é desenhado por revolução (ver TuboMesh), não por painéis; a
  // entrada existe só para o mapa continuar exaustivo sobre BoxModel.
  tube: { topo: true, fundo: false },
  // Gaveta, tubo e mailer são desenhados por componentes próprios
  // (GavetaMesh, TuboMesh, MailerMesh); as entradas existem para o mapa
  // continuar exaustivo sobre BoxModel.
  drawer: { topo: true, fundo: false },
  mailer: { topo: true, fundo: false },
};

/**
 * Modelos que têm peça móvel — e portanto uma abertura para animar.
 *
 * Vive aqui, e não no motor de precificação, de propósito: é uma pergunta
 * sobre o DESENHO ("esta peça se move?"), não sobre o custo. O RSC, a luva e o
 * saco não têm o que abrir; oferecer um slider inerte para eles seria um
 * controle que mente sobre o que faz.
 */
export function hasAperture(model: BoxModel): boolean {
  return hasSeparateLid(model) || model === "drawer" || model === "mailer";
}

/** Segmentos da revolução: acima disso o ganho visual não paga o custo. */
const TUBO_SEGMENTOS = 64;

/**
 * Quanto a gaveta aparece para fora da luva, em fração da profundidade.
 *
 * Desenhar a gaveta fechada mostraria só uma cinta retangular — o usuário não
 * teria como saber que escolheu uma caixa de duas peças. Puxada, a imagem
 * explica o modelo sozinha.
 */
const SLIDE_RATIO = 0.45;

/**
 * Abertura da tampa articulada da mailer, em radianos (~75°).
 *
 * Mesma razão do SLIDE_RATIO: fechada, a mailer seria indistinguível de um
 * RSC na tela. Aberta, a dobradiça na parede traseira e a lingueta pendurada
 * identificam o modelo sem precisar de legenda.
 *
 * Exportada porque a normalização da cena e o alvo da câmera precisam saber
 * quanto a tampa aberta cresce em altura — cravar o ângulo em dois lugares
 * faria a caixa sair do quadro assim que um deles mudasse.
 */
export const MAILER_LID_ANGLE = 1.3;

/** Dobra da lingueta em relação ao plano da tampa, com a caixa aberta (~69°). */
const MAILER_TUCK_ANGLE = 1.2;

/**
 * Dobra da lingueta com a caixa FECHADA: exatamente 90° em relação à tampa.
 *
 * Com a tampa deitada, 90° deixa a lingueta apontando para baixo — e como ela
 * tem o comprimento da altura da caixa, encosta no fundo, encaixada por dentro
 * da parede frontal. É literalmente como a mailer fecha.
 */
const MAILER_TUCK_CLOSED = Math.PI / 2;

/** Recuo trapezoidal das abas da tampa, em fração da dimensão livre. */
const FLAP_TAPER_RATIO = 0.3;

/** Raio do canto arredondado do corte de faca, em fração da altura da caixa. */
const FLAP_CORNER_RATIO = 0.3;

/** Segmentos de cada canto arredondado — 6 já lê como curva nessa escala. */
const CORNER_SEGMENTS = 6;

/**
 * Contorno de corte a partir de vértices, com cantos arredondados por vértice.
 *
 * Existe porque uma mailer não é feita de retângulos: a faca corta as abas em
 * trapézio e arredonda os cantos livres, e isso não é decoração — o canto
 * arredondado é o que deixa a aba deslizar para dentro da parede em vez de
 * enganchar, e o trapézio é o que dá a folga para ela entrar. Desenhar tudo
 * retangular mostraria uma peça que, cortada assim, não fecharia.
 *
 * `r = 0` num vértice mantém o canto vivo — é o caso dos vértices que ficam
 * sobre o vinco, onde a aba continua na peça e não há corte nenhum.
 *
 * @param vertices Polígono em sentido anti-horário, com o raio de cada canto.
 */
function contornoDeCorte(
  vertices: Array<{ x: number; y: number; r: number }>,
): THREE.Shape {
  const n = vertices.length;

  // Para cada vértice, onde a curva entra e onde sai. O raio é limitado a
  // metade da menor aresta vizinha, senão cantos consecutivos se comeriam.
  const cantos = vertices.map((v, i) => {
    const anterior = vertices[(i - 1 + n) % n];
    const proximo = vertices[(i + 1) % n];

    const entradaX = v.x - anterior.x;
    const entradaY = v.y - anterior.y;
    const saidaX = proximo.x - v.x;
    const saidaY = proximo.y - v.y;

    const compEntrada = Math.hypot(entradaX, entradaY) || 1;
    const compSaida = Math.hypot(saidaX, saidaY) || 1;
    const r = Math.min(v.r, compEntrada / 2, compSaida / 2);

    return {
      v,
      entrada: {
        x: v.x - (entradaX / compEntrada) * r,
        y: v.y - (entradaY / compEntrada) * r,
      },
      saida: {
        x: v.x + (saidaX / compSaida) * r,
        y: v.y + (saidaY / compSaida) * r,
      },
    };
  });

  const shape = new THREE.Shape();
  shape.moveTo(cantos[0].saida.x, cantos[0].saida.y);

  // Fecha o laço voltando ao vértice 0 (daí o <= n).
  for (let i = 1; i <= n; i++) {
    const canto = cantos[i % n];

    shape.lineTo(canto.entrada.x, canto.entrada.y);
    shape.quadraticCurveTo(
      canto.v.x,
      canto.v.y,
      canto.saida.x,
      canto.saida.y,
    );
  }

  return shape;
}

/**
 * Suaviza a abertura ao longo do tempo e devolve o valor corrente por ref.
 *
 * Arrastar o slider já é contínuo, mas clicar na trilha ou usar as setas do
 * teclado salta o valor — e um salto ao lado das dimensões, que interpolam,
 * pareceria defeito. Interpolar aqui dá o mesmo movimento aos três caminhos.
 *
 * Devolve ref e não estado porque quem consome são transformações de objetos
 * three: atualizá-las por setState custaria um render do React por quadro,
 * exatamente o que o resto deste arquivo evita.
 */
function useAberturaSuave(alvo: number) {
  const atual = useRef(alvo);

  useFrame((_, delta) => {
    // Fator dependente do delta: a suavidade não muda com o FPS da máquina.
    const alpha = 1 - Math.exp(-LERP_SPEED * delta);
    atual.current += (alvo - atual.current) * alpha;
  });

  return atual;
}

/**
 * Converte milímetros em unidades de cena mantendo a PROPORÇÃO real entre
 * largura, altura e profundidade — que é o ponto do preview: o usuário precisa
 * perceber que digitou uma caixa achatada ou uma coluna estreita.
 */
function toSceneScale(
  { widthMm, heightMm, depthMm }: BoxDimensions,
  /** Altura total do conjunto em mm (base + vão + tampa), se houver tampa. */
  alturaConjuntoMm = heightMm,
): {
  size: THREE.Vector3;
  mmPorUnidade: number;
} {
  /*
   * A referência é o CONJUNTO, não só a caixa.
   *
   * Normalizar apenas pela caixa fazia uma tampa alta estourar o quadro: o
   * usuário digitava 160mm de tampa e a peça saía pela borda do canvas.
   * Incluir a altura empilhada aqui garante que tudo caiba, e como o fator é
   * o mesmo nos três eixos, as proporções continuam fiéis.
   */
  const largest = Math.max(widthMm, depthMm, alturaConjuntoMm, 1); // guarda contra 0
  const factor = SCENE_MAX_UNITS / largest;

  return {
    size: new THREE.Vector3(widthMm * factor, heightMm * factor, depthMm * factor),
    mmPorUnidade: factor,
  };
}

/**
 * Altura total do conjunto, em mm: base + vão da vista explodida + tampa.
 *
 * Exportada porque o BoxViewer precisa dela para mirar a câmera no centro do
 * que está desenhado — cravar um alvo fixo descentraliza assim que a tampa
 * muda de tamanho.
 *
 * NÃO recebe a abertura de propósito: mede sempre o conjunto TOTALMENTE
 * ABERTO. Se a normalização acompanhasse o slider, a caixa cresceria enquanto
 * o usuário a fecha — o quadro sobraria e a peça seria reescalada —, dando a
 * impressão de que as medidas mudaram. Fixar na abertura máxima mantém o
 * tamanho estável durante o arrasto e garante que nada saia do quadro.
 */
export function assemblyHeightMm(props: BoxMeshProps): number {
  const modelo = props.boxModel ?? "rsc";

  /*
   * A mailer não tem tampa separada, mas a tampa ABERTA sobe quase uma
   * profundidade inteira acima da caixa. Devolver só a altura da caixa
   * miraria a câmera baixo demais e cortaria a peça no topo do quadro.
   */
  if (modelo === "mailer") {
    return props.heightMm + props.depthMm * Math.sin(MAILER_LID_ANGLE);
  }

  const tampa = resolveLidDimensions(
    modelo,
    props.widthMm,
    props.heightMm,
    isCylindrical(modelo) ? props.widthMm : props.depthMm,
    props.thicknessMm ?? 0,
    {
      lid_width_mm: props.lidWidthMm,
      lid_depth_mm: props.lidDepthMm,
      lid_height_mm: props.lidHeightMm,
    },
  );

  if (!tampa) return props.heightMm;

  return props.heightMm * (1 + LID_GAP_RATIO) + tampa.heightMm;
}

/** Altura do conjunto já convertida para unidades de cena (≤ SCENE_MAX_UNITS). */
export function assemblyHeightUnits(props: BoxMeshProps): number {
  const alturaMm = assemblyHeightMm(props);
  const profundidade = isCylindrical(props.boxModel ?? "rsc")
    ? props.widthMm
    : props.depthMm;
  const largest = Math.max(props.widthMm, profundidade, alturaMm, 1);

  return (alturaMm * SCENE_MAX_UNITS) / largest;
}

/**
 * Material com textura. Isolado em seu próprio componente porque useTexture()
 * suspende, e um hook não pode ser chamado condicionalmente — separar permite
 * envolvê-lo em <Suspense> sem violar as regras dos hooks.
 */
function TexturedMaterial({
  url,
  colorHex,
  side = THREE.FrontSide,
}: {
  url: string;
  colorHex: string;
  side?: THREE.Side;
}) {
  /**
   * A configuração da textura vai no callback do useTexture, e não no corpo do
   * componente. Mutar durante o render é efeito colateral: o React Compiler
   * pode memoizar o valor e pular a mutação, deixando a textura sem repetição
   * e com o espaço de cor errado. O callback roda uma vez, no carregamento.
   */
  const texture = useTexture(url, (loaded) => {
    const textures = Array.isArray(loaded) ? loaded : [loaded];

    for (const item of textures) {
      // Repetir em vez de esticar: papelão e tecido têm trama, e uma trama
      // esticada em caixas grandes denuncia o preview como falso.
      item.wrapS = item.wrapT = THREE.RepeatWrapping;
      item.repeat.set(2, 2);
      item.colorSpace = THREE.SRGBColorSpace;
    }
  });

  return (
    <meshStandardMaterial
      map={texture}
      color={colorHex}
      roughness={0.85}
      metalness={0.02}
      side={side}
    />
  );
}

function FlatMaterial({
  colorHex,
  side = THREE.FrontSide,
}: {
  colorHex: string;
  side?: THREE.Side;
}) {
  return (
    <meshStandardMaterial
      color={colorHex}
      // Papelão/papel são superfícies foscas: brilho especular alto faria a
      // peça parecer plástico e distorceria a leitura do material escolhido.
      roughness={0.9}
      metalness={0.0}
      side={side}
    />
  );
}

/**
 * Um painel da caixa (fundo, tampa ou parede).
 *
 * Recebe geometria unitária e é dimensionado por escala no useFrame do pai:
 * assim as paredes acompanham a animação sem recriar BufferGeometry a cada
 * tecla digitada.
 */
const Painel = ({
  refObj,
  material,
  showEdges,
}: {
  refObj: React.RefObject<THREE.Mesh | null>;
  material: ReactNode;
  showEdges: boolean;
}) => (
  <mesh ref={refObj} castShadow receiveShadow>
    <boxGeometry args={[1, 1, 1]} />
    {material}
    {/* Arestas escurecidas: sugerem os vincos de dobra da planificação. */}
    {showEdges && <Edges threshold={15} color="#00000030" />}
  </mesh>
);

/**
 * Caixa gaveta: luva externa + gaveta deslizando para fora.
 *
 * Componente próprio, como o TuboMesh, porque são DUAS peças com posições
 * relativas — não cabe no arranjo de painéis de uma caixa única.
 *
 * As medidas informadas são as internas da gaveta; a luva é derivada delas,
 * vencendo as paredes da gaveta e a folga de deslize, exatamente como no
 * cálculo de material.
 */
function GavetaMesh({
  widthMm,
  heightMm,
  depthMm,
  espessuraMm,
  abertura,
  material,
  showEdges,
}: {
  widthMm: number;
  heightMm: number;
  depthMm: number;
  espessuraMm: number;
  abertura: number;
  material: ReactNode;
  showEdges: boolean;
}) {
  // Usa o deslize MÁXIMO, não o atual: normalizar pela posição corrente faria
  // a peça inchar conforme o usuário fecha a gaveta.
  const profundidadeVisivel = depthMm * (1 + SLIDE_RATIO);
  const fator =
    SCENE_MAX_UNITS / Math.max(widthMm, heightMm, profundidadeVisivel, 1);

  const w = widthMm * fator;
  const h = heightMm * fator;
  const d = depthMm * fator;

  // Espessura visível limitada a um terço da menor dimensão, senão em caixas
  // pequenas as paredes se atravessam.
  const t = Math.min(
    Math.max(espessuraMm * fator, MIN_WALL_UNITS),
    w / 3,
    h / 3,
  );

  // Folga de deslize: a mesma ideia do cálculo, em proporção visível.
  const folga = t * 0.6;

  // Gaveta montada (fundo + paredes) e seção interna da luva em volta dela.
  const gavetaW = w + 2 * t;
  const gavetaH = h + t;
  const secaoW = gavetaW + folga;
  const secaoH = gavetaH + folga;

  const deslizeMaximo = d * SLIDE_RATIO;
  const deslize = deslizeMaximo * abertura;

  const gavetaRef = useRef<THREE.Group>(null);
  const suave = useAberturaSuave(abertura);

  useFrame(() => {
    if (gavetaRef.current) {
      gavetaRef.current.position.z = deslizeMaximo * suave.current;
    }
  });

  const painel = (
    sx: number,
    sy: number,
    sz: number,
    px: number,
    py: number,
    pz: number,
    key: string,
  ) => (
    <mesh key={key} scale={[sx, sy, sz]} position={[px, py, pz]} castShadow receiveShadow>
      <boxGeometry args={[1, 1, 1]} />
      {material}
      {showEdges && <Edges threshold={15} color="#00000030" />}
    </mesh>
  );

  return (
    // Eleva o conjunto para que a base da luva encoste no chão.
    <group position={[0, t, 0]}>
      {/* ── Luva: cinta fechada, aberta nas duas pontas ─────────────────── */}
      {painel(secaoW + 2 * t, t, d, 0, -t / 2, 0, "luva-fundo")}
      {painel(secaoW + 2 * t, t, d, 0, secaoH + t / 2, 0, "luva-topo")}
      {painel(t, secaoH, d, -(secaoW + t) / 2, secaoH / 2, 0, "luva-esq")}
      {painel(t, secaoH, d, (secaoW + t) / 2, secaoH / 2, 0, "luva-dir")}

      {/*
       * ── Gaveta: bandeja que desliza ───────────────────────────────────
       *
       * As cinco peças vivem num grupo próprio para que o deslize seja UMA
       * transformação animada, e não cinco posições recalculadas em paralelo
       * — que poderiam sair de sincronia entre si durante o movimento.
       */}
      <group ref={gavetaRef} position={[0, 0, deslize]}>
        {painel(gavetaW, t, d, 0, t / 2, 0, "gav-fundo")}
        {painel(t, gavetaH, d, -(gavetaW - t) / 2, gavetaH / 2, 0, "gav-esq")}
        {painel(t, gavetaH, d, (gavetaW - t) / 2, gavetaH / 2, 0, "gav-dir")}
        {painel(gavetaW - 2 * t, gavetaH, t, 0, gavetaH / 2, (d - t) / 2, "gav-frente")}
        {painel(gavetaW - 2 * t, gavetaH, t, 0, gavetaH / 2, -(d - t) / 2, "gav-tras")}
      </group>
    </group>
  );
}

/**
 * Mailer box (RETT): peça única com tampa articulada e laterais roladas.
 *
 * Componente próprio, como GavetaMesh e TuboMesh, porque a tampa gira em
 * torno de uma dobradiça e a lingueta gira em torno da tampa — dois pivôs
 * aninhados que não cabem no arranjo de painéis independentes da caixa comum.
 *
 * Desenhada ABERTA de propósito: fechada, a mailer é visualmente idêntica a
 * um RSC, e o usuário não teria como confirmar que escolheu o modelo certo.
 */
function MailerMesh({
  widthMm,
  heightMm,
  depthMm,
  espessuraMm,
  abertura,
  material,
  showEdges,
}: {
  widthMm: number;
  heightMm: number;
  depthMm: number;
  espessuraMm: number;
  abertura: number;
  material: ReactNode;
  showEdges: boolean;
}) {
  // Normaliza pela abertura MÁXIMA, não pela atual: assim a caixa não muda de
  // tamanho enquanto o usuário arrasta o slider.
  const alturaVisivel = heightMm + depthMm * Math.sin(MAILER_LID_ANGLE);
  const fator = SCENE_MAX_UNITS / Math.max(widthMm, alturaVisivel, depthMm, 1);

  const w = widthMm * fator;
  const h = heightMm * fator;
  const d = depthMm * fator;

  // A lateral rolada é DUPLA (2t), então o teto da espessura visível é w/6 e
  // não w/3: senão as duas paredes se encontrariam no meio da caixa.
  // Teto em w/8 (e não w/6 como nas outras caixas) porque a mailer empilha
  // três camadas de cada lado: a lateral rolada (2t) mais a aba da tampa por
  // dentro dela. Com o teto mais folgado, material grosso faria as abas dos
  // dois lados se encontrarem no meio da caixa.
  const t = Math.min(Math.max(espessuraMm * fator, MIN_WALL_UNITS), w / 8, h / 3);

  // Vão livre entre as duas laterais roladas.
  const larguraInterna = Math.max(w - 4 * t, 0.001);

  /*
   * Abas laterais da tampa: descem por DENTRO das paredes ao fechar e são o
   * que impede a tampa de abrir sozinha. Já entravam no consumo de material
   * (o termo 2 × altura × profundidade do blank) — faltava desenhá-las, e o
   * preview mostrava menos peça do que o orçamento cobrava.
   */
  const abaX = w / 2 - 3 * t; // encaixada logo dentro da lateral rolada
  const abaProfundidade = Math.max(d - 2 * t, 0.001); // cabe entre frente e trás

  // A lingueta passa ENTRE as duas abas, então é mais estreita que o vão das
  // paredes. Usar larguraInterna aqui faria as três peças se atravessarem
  // justamente na posição fechada.
  const larguraEntreAbas = Math.max(w - 8 * t, 0.001);

  /*
   * ── Contornos de faca ───────────────────────────────────────────────────
   *
   * Memorizados pelas MEDIDAS, e não pela abertura: sem isso cada quadro do
   * arrasto do slider reconstruiria as três geometrias extrudadas.
   */
  const raioCanto = h * FLAP_CORNER_RATIO;

  const formaLingueta = useMemo(() => {
    const meia = larguraEntreAbas / 2;
    // Um pouco menor que a altura: a lingueta tem de entrar sem bater no fundo.
    const comprimento = h * 0.92;
    const recuo = larguraEntreAbas * FLAP_TAPER_RATIO * 0.5;

    // Trapézio: largura cheia no vinco, estreitando até a borda livre. Os dois
    // cantos livres são arredondados; os do vinco ficam vivos porque ali não
    // há corte — a chapa continua na tampa.
    return contornoDeCorte([
      { x: -meia, y: 0, r: 0 },
      { x: meia, y: 0, r: 0 },
      { x: meia - recuo, y: comprimento, r: raioCanto },
      { x: -meia + recuo, y: comprimento, r: raioCanto },
    ]);
  }, [larguraEntreAbas, h, raioCanto]);

  const formaAbaLateral = useMemo(() => {
    // Já desenhada na posição DEPOIS de dobrada: x corre pela profundidade
    // (x = 0 é a ponta frontal), y desce da tampa para o fundo da caixa.
    const alturaAba = h * 0.94;
    const recuo = abaProfundidade * FLAP_TAPER_RATIO;

    return contornoDeCorte([
      { x: 0, y: 0, r: 0 }, // ponta frontal, sobre o vinco
      { x: abaProfundidade, y: 0, r: 0 }, // extremo da dobradiça, sobre o vinco
      { x: abaProfundidade, y: -alturaAba, r: raioCanto * 0.5 },
      { x: recuo, y: -alturaAba, r: raioCanto },
    ]);
  }, [abaProfundidade, h, raioCanto]);

  // Opções de extrusão memorizadas junto: o R3F compara `args` elemento a
  // elemento, então referências estáveis evitam recriar a geometria por quadro.
  const extrusao = useMemo(
    () => ({ depth: t, bevelEnabled: false, curveSegments: CORNER_SEGMENTS }),
    [t],
  );

  const pecaRecortada = (
    shape: THREE.Shape,
    position: [number, number, number],
    rotation: [number, number, number],
    key: string,
  ) => (
    <mesh key={key} position={position} rotation={rotation} castShadow receiveShadow>
      <extrudeGeometry args={[shape, extrusao]} />
      {material}
      {showEdges && <Edges threshold={15} color="#00000030" />}
    </mesh>
  );

  /*
   * Os dois pivôs da mailer, animados por ref.
   *
   * A rotação inicial no JSX vem da abertura ALVO para que o primeiro quadro
   * já saia correto; a partir daí quem manda é o useFrame.
   */
  const tampaRef = useRef<THREE.Group>(null);
  const linguetaRef = useRef<THREE.Group>(null);
  const suave = useAberturaSuave(abertura);

  const anguloTampa = MAILER_LID_ANGLE * abertura;
  const anguloLingueta =
    MAILER_TUCK_CLOSED + (MAILER_TUCK_ANGLE - MAILER_TUCK_CLOSED) * abertura;

  useFrame(() => {
    const a = suave.current;

    if (tampaRef.current) {
      tampaRef.current.rotation.x = -MAILER_LID_ANGLE * a;
    }
    if (linguetaRef.current) {
      linguetaRef.current.rotation.x =
        MAILER_TUCK_CLOSED + (MAILER_TUCK_ANGLE - MAILER_TUCK_CLOSED) * a;
    }
  });

  const painel = (
    sx: number,
    sy: number,
    sz: number,
    px: number,
    py: number,
    pz: number,
    key: string,
  ) => (
    <mesh key={key} scale={[sx, sy, sz]} position={[px, py, pz]} castShadow receiveShadow>
      <boxGeometry args={[1, 1, 1]} />
      {material}
      {showEdges && <Edges threshold={15} color="#00000030" />}
    </mesh>
  );

  return (
    <group>
      {painel(w, t, d, 0, t / 2, 0, "fundo")}

      {/* Laterais ROLADAS: parede dupla — a assinatura estrutural do modelo. */}
      {painel(2 * t, h, d, -(w - 2 * t) / 2, t + h / 2, 0, "lateral-esq")}
      {painel(2 * t, h, d, (w - 2 * t) / 2, t + h / 2, 0, "lateral-dir")}

      {/* Paredes frontal e traseira, encaixadas entre as laterais. */}
      {painel(larguraInterna, h, t, 0, t + h / 2, (d - t) / 2, "frente")}
      {painel(larguraInterna, h, t, 0, t + h / 2, -(d - t) / 2, "tras")}

      {/* Tampa articulada: o pivô É a dobradiça, no topo da parede traseira. */}
      <group ref={tampaRef} position={[0, t + h, -d / 2]} rotation={[-anguloTampa, 0, 0]}>
        {painel(w, t, d, 0, t / 2, d / 2, "tampa")}

        {/*
         * As duas abas laterais, vincadas a 90° da tampa.
         *
         * O ângulo é FIXO e não acompanha o slider: o vinco existe na chapa,
         * então a aba é perpendicular à tampa o tempo todo. Como são filhas do
         * grupo da tampa, giram junto com ela — abertas apontam para fora,
         * fechadas descem retas para dentro da caixa. É exatamente o movimento
         * da peça real, e sai de graça da hierarquia.
         */}
        {[-1, 1].map((lado) =>
          /*
           * A rotação em Y põe a extrusão (a espessura da chapa) no eixo X, e
           * o contorno passa a ocupar o plano vertical da lateral. Como a
           * forma já foi desenhada dobrada, os dois lados usam a MESMA
           * geometria — só mudam de posição, sem espelhamento que inverteria
           * as normais.
           */
          pecaRecortada(
            formaAbaLateral,
            [lado * abaX - t / 2, 0, d - t],
            [0, Math.PI / 2, 0],
            `aba-${lado}`,
          ),
        )}

        {/*
         * Lingueta: dobra na ponta da tampa e entra por dentro da frente.
         *
         * O ângulo dela acompanha a abertura em sentido CONTRÁRIO ao da tampa.
         * Fechando, ela endireita para 90° e desce por dentro da parede
         * frontal; abrindo, relaxa para fora. Mantê-lo fixo faria a lingueta
         * girar junto com a tampa e apontar para o céu com a caixa fechada.
         *
         * O pivô recua duas espessuras da borda para que, fechada, ela caia
         * DENTRO da parede frontal e não encostada por fora.
         */}
        <group ref={linguetaRef} position={[0, 0, d - 2 * t]} rotation={[anguloLingueta, 0, 0]}>
          {/* Rotação em X leva o comprimento do contorno para o eixo Z (para
              fora do vinco) e a extrusão para Y (a espessura da chapa). */}
          {pecaRecortada(formaLingueta, [0, t / 2, 0], [Math.PI / 2, 0, 0], "lingueta")}
        </group>
      </group>
    </group>
  );
}

/**
 * Perfil de um copo aberto em cima, para revolução.
 *
 * Os pontos traçam: fundo externo → parede externa → bordo → parede interna →
 * piso interno. Começar e terminar sobre o eixo (x = 0) fecha o sólido.
 *
 * Por que LatheGeometry e não um cilindro simples: um `cylinderGeometry` com
 * `openEnded` é uma casca sem espessura — o bordo some quando visto de cima e
 * a peça não comunica a espessura do material. A revolução de um perfil em
 * "U" produz um tubo com parede real, coerente com as caixas ocas.
 */
function perfilCopo(rInterno: number, rExterno: number, altura: number, fundo: number) {
  return [
    new THREE.Vector2(0, 0),
    new THREE.Vector2(rExterno, 0),
    new THREE.Vector2(rExterno, altura),
    new THREE.Vector2(rInterno, altura),
    new THREE.Vector2(rInterno, fundo),
    new THREE.Vector2(0, fundo),
  ];
}

/** Perfil da tampa: um copo invertido, aberto embaixo. */
function perfilTampa(rInterno: number, rExterno: number, altura: number, topo: number) {
  return [
    new THREE.Vector2(0, altura),
    new THREE.Vector2(rExterno, altura),
    new THREE.Vector2(rExterno, 0),
    new THREE.Vector2(rInterno, 0),
    new THREE.Vector2(rInterno, altura - topo),
    new THREE.Vector2(0, altura - topo),
  ];
}

/**
 * Embalagem cilíndrica: corpo + tampa, ambos por revolução.
 *
 * Não interpola as dimensões como a caixa: a geometria de revolução é
 * reconstruída a cada mudança de medida, e animar por escala engrossaria a
 * parede junto com o diâmetro — uma mentira visual sobre o material. Como o
 * perfil tem 6 pontos e 64 segmentos, reconstruir é barato.
 */
function TuboMesh({
  diametroMm,
  alturaMm,
  espessuraMm,
  tampaMm,
  abertura,
  colorHex,
  textureUrl,
  showEdges,
}: {
  diametroMm: number;
  alturaMm: number;
  espessuraMm: number;
  tampaMm: { widthMm: number; heightMm: number } | null;
  abertura: number;
  colorHex: string;
  textureUrl?: string | null;
  showEdges: boolean;
}) {
  /*
   * DoubleSide, e não o FrontSide dos painéis.
   *
   * O LatheGeometry gera os triângulos com orientação oposta à das normais
   * que ele mesmo calcula. Com backface culling, a parede voltada para a
   * câmera é descartada e o que aparece é a superfície de trás — o tubo
   * parecia translúcido e com as cores invertidas.
   *
   * Desenhar as duas faces também é o correto para um recipiente ABERTO:
   * o interior da peça é superfície visível de verdade, não um artefato.
   * O shader inverte a normal nas faces de trás, então a iluminação sai
   * certa dos dois lados.
   */
  const material = (
    <Suspense fallback={<FlatMaterial colorHex={colorHex} side={THREE.DoubleSide} />}>
      {textureUrl ? (
        <TexturedMaterial url={textureUrl} colorHex={colorHex} side={THREE.DoubleSide} />
      ) : (
        <FlatMaterial colorHex={colorHex} side={THREE.DoubleSide} />
      )}
    </Suspense>
  );
  const alturaConjunto = tampaMm
    ? alturaMm * (1 + LID_GAP_RATIO) + tampaMm.heightMm
    : alturaMm;

  const fator = SCENE_MAX_UNITS / Math.max(diametroMm, alturaConjunto, 1);

  // Espessura visível com o mesmo piso das caixas, e limitada a um terço do
  // raio para que tubos estreitos não virem um bastão maciço.
  const raio = (diametroMm / 2) * fator;
  const t = Math.min(Math.max(espessuraMm * fator, MIN_WALL_UNITS), raio / 3);

  const altura = alturaMm * fator;

  const corpo = perfilCopo(raio, raio + t, altura, t);

  const alturaTampa = (tampaMm?.heightMm ?? 0) * fator;

  /*
   * Fechada, a saia da tampa CAPA o topo do corpo e o tampo pousa no bordo —
   * por isso a base dela desce uma altura de tampa. Apenas zerar o vão a
   * deixaria pousada em cima com a saia apontando para fora do corpo, que é o
   * oposto de uma tampa fechada.
   *
   * O piso em zero cobre a tampa mais alta que o próprio tubo: nesse caso ela
   * capa o corpo inteiro e a saia encosta no chão, em vez de afundar.
   */
  const tampaFechadaY = Math.max(altura - alturaTampa, 0);
  const tampaAbertaY = altura + altura * LID_GAP_RATIO;

  const tampa = tampaMm
    ? {
        // lid.widthMm é o diâmetro INTERNO da tampa: ela desliza por fora do
        // corpo, então o raio interno dela já embute a folga de encaixe.
        perfil: perfilTampa(
          (tampaMm.widthMm / 2) * fator,
          (tampaMm.widthMm / 2) * fator + t,
          alturaTampa,
          t,
        ),
        y: tampaFechadaY + (tampaAbertaY - tampaFechadaY) * abertura,
      }
    : null;

  const tampaRef = useRef<THREE.Mesh>(null);
  const suave = useAberturaSuave(abertura);

  useFrame(() => {
    if (tampaRef.current) {
      tampaRef.current.position.y =
        tampaFechadaY + (tampaAbertaY - tampaFechadaY) * suave.current;
    }
  });

  return (
    <group>
      <mesh castShadow receiveShadow>
        <latheGeometry args={[corpo, TUBO_SEGMENTOS]} />
        {material}
        {showEdges && <Edges threshold={30} color="#00000030" />}
      </mesh>

      {tampa && (
        <mesh ref={tampaRef} position={[0, tampa.y, 0]} castShadow receiveShadow>
          <latheGeometry args={[tampa.perfil, TUBO_SEGMENTOS]} />
          {material}
          {showEdges && <Edges threshold={30} color="#00000030" />}
        </mesh>
      )}
    </group>
  );
}

/**
 * A malha da embalagem — uma caixa OCA, montada painel a painel.
 *
 * Por que não um cubo maciço: um bloco sólido não comunica que aquilo é um
 * recipiente. Com as paredes separadas o usuário enxerga o volume interno,
 * percebe a espessura do material que escolheu e entende de imediato a
 * diferença entre uma bandeja aberta e um saco fechado.
 *
 * Responsabilidade única: desenhar a embalagem com as proporções recebidas.
 * Não conhece Canvas, câmera, luzes nem estado da aplicação — o que permite
 * reusá-la em thumbnails, no PDF do orçamento ou em testes.
 */
export function BoxMesh({
  widthMm,
  heightMm,
  depthMm,
  boxModel = "rsc",
  thicknessMm,
  colorHex = "#C8A06A",
  textureUrl,
  showEdges = true,
  aperture = 1,
  lidWidthMm = null,
  lidDepthMm = null,
  lidHeightMm = null,
}: BoxMeshProps) {
  // Blindagem contra valor fora da faixa: o slider entrega 0–1, mas o
  // componente é público e uma abertura negativa inverteria a dobradiça.
  const abertura = Math.min(Math.max(aperture, 0), 1);
  const aberturaSuave = useAberturaSuave(abertura);
  const grupo = useRef<THREE.Group>(null);

  const fundo = useRef<THREE.Mesh>(null);
  const tampa = useRef<THREE.Mesh>(null);
  const esquerda = useRef<THREE.Mesh>(null);
  const direita = useRef<THREE.Mesh>(null);
  const frente = useRef<THREE.Mesh>(null);
  const tras = useRef<THREE.Mesh>(null);

  // Tampa telescópica (só o modelo "bandeja"): topo + 4 saias descendo.
  const tampaTopo = useRef<THREE.Mesh>(null);
  const tampaEsq = useRef<THREE.Mesh>(null);
  const tampaDir = useRef<THREE.Mesh>(null);
  const tampaFrente = useRef<THREE.Mesh>(null);
  const tampaTras = useRef<THREE.Mesh>(null);

  /**
   * Alvo da animação: valor comum de render, capturado por closure no useFrame.
   *
   * Não é um ref de propósito — mutar ref durante o render é efeito colateral
   * (o React Compiler acusa). Como o callback do useFrame é recriado a cada
   * render, ele sempre enxerga o alvo mais recente.
   */
  const { size: alvo, mmPorUnidade } = toSceneScale(
    { widthMm, heightMm, depthMm },
    assemblyHeightMm({
      widthMm,
      heightMm,
      depthMm,
      boxModel,
      thicknessMm,
      lidWidthMm,
      lidDepthMm,
      lidHeightMm,
    }),
  );

  const aberturas = APERTURAS[boxModel] ?? APERTURAS.rsc;

  /**
   * Medidas da tampa vindas do motor de precificação compartilhado.
   *
   * Não recalculamos folga e altura aqui: são regra de negócio, definidas em
   * lidDimensions() e espelhadas no PHP. Duplicá-las no componente faria o
   * desenho divergir das medidas exibidas ao usuário.
   */
  const profundidadeEfetiva = isCylindrical(boxModel) ? widthMm : depthMm;

  const tampaMm = resolveLidDimensions(
    boxModel,
    widthMm,
    heightMm,
    profundidadeEfetiva,
    thicknessMm ?? 0,
    {
      lid_width_mm: lidWidthMm,
      lid_depth_mm: lidDepthMm,
      lid_height_mm: lidHeightMm,
    },
  );
  const temTampa = tampaMm !== null;

  // Espessura real do material convertida para a escala da cena, com piso
  // visual para que materiais finos ainda apareçam como parede.
  const espessura = Math.max((thicknessMm ?? 0) * mmPorUnidade, MIN_WALL_UNITS);

  /**
   * Por que interpolar em vez de aplicar as dimensões direto:
   * o usuário digita "300" em três teclas, o que produziria os estados
   * 3 → 30 → 300 e uma caixa que salta na tela. O lerp transforma isso em um
   * crescimento contínuo e legível.
   */
  const atual = useRef(new THREE.Vector3(0.001, 0.001, 0.001));

  useFrame((_, delta) => {
    if (!grupo.current) return;

    // Fator dependente do delta => a suavidade não muda com o FPS da máquina.
    const alpha = 1 - Math.exp(-LERP_SPEED * delta);
    atual.current.lerp(alvo, alpha);

    const w = atual.current.x;
    const h = atual.current.y;
    const d = atual.current.z;

    // A parede nunca pode ser mais grossa que metade da caixa, senão as faces
    // opostas se atravessam e a peça vira um bloco sólido invertido.
    const t = Math.min(espessura, w / 2.5, h / 2.5, d / 2.5);

    // Largura interna das paredes frente/trás: descontam as laterais para que
    // os painéis encostem sem sobrepor (sobreposição causa z-fighting).
    const larguraInterna = Math.max(w - 2 * t, 0.001);

    const set = (
      ref: React.RefObject<THREE.Mesh | null>,
      sx: number,
      sy: number,
      sz: number,
      px: number,
      py: number,
      pz: number,
    ) => {
      if (!ref.current) return;
      ref.current.scale.set(sx, sy, sz);
      ref.current.position.set(px, py, pz);
    };

    // Fundo e tampa: chapas horizontais nas extremidades da altura.
    set(fundo, w, t, d, 0, t / 2, 0);
    set(tampa, w, t, d, 0, h - t / 2, 0);

    // Laterais: altura cheia, recuadas meia espessura para dentro.
    set(esquerda, t, h, d, -(w - t) / 2, h / 2, 0);
    set(direita, t, h, d, (w - t) / 2, h / 2, 0);

    // Frente e trás: encaixam ENTRE as laterais.
    set(frente, larguraInterna, h, t, 0, h / 2, (d - t) / 2);
    set(tras, larguraInterna, h, t, 0, h / 2, -(d - t) / 2);

    // ── Tampa telescópica ────────────────────────────────────────────────
    if (!temTampa || !tampaMm) return;

    // Converte as medidas em mm para a escala da cena usando o MESMO fator da
    // base — assim a folga de encaixe aparece na proporção correta.
    const fator = alvo.x / Math.max(widthMm, 1);
    const tw = tampaMm.widthMm * fator;
    const td = tampaMm.depthMm * fator;
    const th = tampaMm.heightMm * fator;

    /*
     * Posição vertical da tampa, entre fechada e aberta.
     *
     * Fechada, a saia telescópica CAPA a parte de cima da base e o tampo pousa
     * no bordo — daí descer uma altura de tampa. Zerar só o vão a deixaria
     * flutuando acima da caixa com a saia para fora, que não é uma tampa
     * fechada. O piso em zero cobre a tampa mais alta que a própria base.
     */
    const baseFechada = Math.max(h - th, 0);
    const baseAberta = h + h * LID_GAP_RATIO;
    const base = baseFechada + (baseAberta - baseFechada) * aberturaSuave.current;

    const twInterna = Math.max(tw - 2 * t, 0.001);

    // Topo da tampa.
    set(tampaTopo, tw, t, td, 0, base + th - t / 2, 0);

    // Saias descendo a partir do topo.
    set(tampaEsq, t, th, td, -(tw - t) / 2, base + th / 2, 0);
    set(tampaDir, t, th, td, (tw - t) / 2, base + th / 2, 0);
    set(tampaFrente, twInterna, th, t, 0, base + th / 2, (td - t) / 2);
    set(tampaTras, twInterna, th, t, 0, base + th / 2, -(td - t) / 2);
  });

  // Um elemento de material reutilizado em todos os painéis. A textura em si é
  // cacheada por URL pelo useTexture, então não há recarga por painel.
  const material = (
    <Suspense fallback={<FlatMaterial colorHex={colorHex} />}>
      {textureUrl ? (
        <TexturedMaterial url={textureUrl} colorHex={colorHex} />
      ) : (
        <FlatMaterial colorHex={colorHex} />
      )}
    </Suspense>
  );

  /*
   * Modelos cilíndricos são desenhados por revolução, não por painéis planos.
   * O desvio fica aqui e não em outro componente da árvore para que o
   * chamador (BoxViewer) não precise saber que existem duas famílias de
   * geometria — ele só pede "desenhe a embalagem".
   */
  if (boxModel === "mailer") {
    return (
      <MailerMesh
        widthMm={widthMm}
        heightMm={heightMm}
        depthMm={depthMm}
        espessuraMm={thicknessMm ?? 0}
        abertura={abertura}
        material={material}
        showEdges={showEdges}
      />
    );
  }

  if (boxModel === "drawer") {
    return (
      <GavetaMesh
        widthMm={widthMm}
        heightMm={heightMm}
        depthMm={depthMm}
        espessuraMm={thicknessMm ?? 0}
        abertura={abertura}
        material={material}
        showEdges={showEdges}
      />
    );
  }

  if (isCylindrical(boxModel)) {
    return (
      <TuboMesh
        diametroMm={widthMm}
        alturaMm={heightMm}
        espessuraMm={thicknessMm ?? 0}
        tampaMm={tampaMm}
        abertura={abertura}
        colorHex={colorHex}
        textureUrl={textureUrl}
        showEdges={showEdges}
      />
    );
  }

  return (
    <group ref={grupo}>
      {/* Fundo: presente em tudo, menos na luva (tubo aberto nas duas pontas). */}
      {!aberturas.fundo && <Painel refObj={fundo} material={material} showEdges={showEdges} />}

      {/* Tampa: ausente na bandeja e no RSC — é a "caixa sem tampa". */}
      {!aberturas.topo && <Painel refObj={tampa} material={material} showEdges={showEdges} />}

      <Painel refObj={esquerda} material={material} showEdges={showEdges} />
      <Painel refObj={direita} material={material} showEdges={showEdges} />
      <Painel refObj={frente} material={material} showEdges={showEdges} />
      <Painel refObj={tras} material={material} showEdges={showEdges} />

      {/* Tampa telescópica, flutuando acima da base (vista explodida). */}
      {temTampa && (
        <>
          <Painel refObj={tampaTopo} material={material} showEdges={showEdges} />
          <Painel refObj={tampaEsq} material={material} showEdges={showEdges} />
          <Painel refObj={tampaDir} material={material} showEdges={showEdges} />
          <Painel refObj={tampaFrente} material={material} showEdges={showEdges} />
          <Painel refObj={tampaTras} material={material} showEdges={showEdges} />
        </>
      )}
    </group>
  );
}

"use client";

import { useRef, Suspense, type ReactNode } from "react";
import { useFrame } from "@react-three/fiber";
import { Edges, useTexture } from "@react-three/drei";
import * as THREE from "three";
import type { BoxModel } from "@/lib/pricing/types";
import { resolveLidDimensions, isCylindrical } from "@/lib/pricing/engine";

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
};

/** Segmentos da revolução: acima disso o ganho visual não paga o custo. */
const TUBO_SEGMENTOS = 64;

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
 */
export function assemblyHeightMm(props: BoxMeshProps): number {
  const modelo = props.boxModel ?? "rsc";

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
  colorHex,
  textureUrl,
  showEdges,
}: {
  diametroMm: number;
  alturaMm: number;
  espessuraMm: number;
  tampaMm: { widthMm: number; heightMm: number } | null;
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

  const tampa = tampaMm
    ? {
        // lid.widthMm é o diâmetro INTERNO da tampa: ela desliza por fora do
        // corpo, então o raio interno dela já embute a folga de encaixe.
        perfil: perfilTampa(
          (tampaMm.widthMm / 2) * fator,
          (tampaMm.widthMm / 2) * fator + t,
          tampaMm.heightMm * fator,
          t,
        ),
        y: altura + altura * LID_GAP_RATIO,
      }
    : null;

  return (
    <group>
      <mesh castShadow receiveShadow>
        <latheGeometry args={[corpo, TUBO_SEGMENTOS]} />
        {material}
        {showEdges && <Edges threshold={30} color="#00000030" />}
      </mesh>

      {tampa && (
        <mesh position={[0, tampa.y, 0]} castShadow receiveShadow>
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
  lidWidthMm = null,
  lidDepthMm = null,
  lidHeightMm = null,
}: BoxMeshProps) {
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

    // Altura da base já animada + o vão da vista explodida.
    const base = h + h * LID_GAP_RATIO;
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
  if (isCylindrical(boxModel)) {
    return (
      <TuboMesh
        diametroMm={widthMm}
        alturaMm={heightMm}
        espessuraMm={thicknessMm ?? 0}
        tampaMm={tampaMm}
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

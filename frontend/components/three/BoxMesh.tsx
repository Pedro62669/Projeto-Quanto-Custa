"use client";

import { useRef, Suspense, type ReactNode } from "react";
import { useFrame } from "@react-three/fiber";
import { Edges, useTexture } from "@react-three/drei";
import * as THREE from "three";
import type { BoxModel } from "@/lib/pricing/types";

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
};

/**
 * Converte milímetros em unidades de cena mantendo a PROPORÇÃO real entre
 * largura, altura e profundidade — que é o ponto do preview: o usuário precisa
 * perceber que digitou uma caixa achatada ou uma coluna estreita.
 */
function toSceneScale({ widthMm, heightMm, depthMm }: BoxDimensions): {
  size: THREE.Vector3;
  mmPorUnidade: number;
} {
  const largest = Math.max(widthMm, heightMm, depthMm, 1); // guarda contra 0
  const factor = SCENE_MAX_UNITS / largest;

  return {
    size: new THREE.Vector3(widthMm * factor, heightMm * factor, depthMm * factor),
    mmPorUnidade: factor,
  };
}

/**
 * Material com textura. Isolado em seu próprio componente porque useTexture()
 * suspende, e um hook não pode ser chamado condicionalmente — separar permite
 * envolvê-lo em <Suspense> sem violar as regras dos hooks.
 */
function TexturedMaterial({ url, colorHex }: { url: string; colorHex: string }) {
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

  return <meshStandardMaterial map={texture} color={colorHex} roughness={0.85} metalness={0.02} />;
}

function FlatMaterial({ colorHex }: { colorHex: string }) {
  return (
    <meshStandardMaterial
      color={colorHex}
      // Papelão/papel são superfícies foscas: brilho especular alto faria a
      // peça parecer plástico e distorceria a leitura do material escolhido.
      roughness={0.9}
      metalness={0.0}
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
}: BoxMeshProps) {
  const grupo = useRef<THREE.Group>(null);

  const fundo = useRef<THREE.Mesh>(null);
  const tampa = useRef<THREE.Mesh>(null);
  const esquerda = useRef<THREE.Mesh>(null);
  const direita = useRef<THREE.Mesh>(null);
  const frente = useRef<THREE.Mesh>(null);
  const tras = useRef<THREE.Mesh>(null);

  /**
   * Alvo da animação: valor comum de render, capturado por closure no useFrame.
   *
   * Não é um ref de propósito — mutar ref durante o render é efeito colateral
   * (o React Compiler acusa). Como o callback do useFrame é recriado a cada
   * render, ele sempre enxerga o alvo mais recente.
   */
  const { size: alvo, mmPorUnidade } = toSceneScale({ widthMm, heightMm, depthMm });

  const aberturas = APERTURAS[boxModel] ?? APERTURAS.rsc;

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
    </group>
  );
}

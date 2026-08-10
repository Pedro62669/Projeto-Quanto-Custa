"use client";

import {
  useRef,
  useMemo,
  useEffect,
  useLayoutEffect,
  useState,
  Suspense,
  type ReactNode,
} from "react";
import { useFrame } from "@react-three/fiber";
import { Edges, useTexture, useGLTF, useAnimations } from "@react-three/drei";
import * as THREE from "three";
import type { BoxModel } from "@/lib/pricing/types";
import {
  resolveLidDimensions,
  isCylindrical,
  hasSeparateLid,
  isBook,
  bookLayout,
  suggestedMagnets,
} from "@/lib/pricing/engine";

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
  // A gaveta é desenhada por componente próprio (GavetaMesh); a entrada
  // existe para o mapa continuar exaustivo sobre BoxModel.
  drawer: { topo: true, fundo: false },
  // Idem para a mailer (MailerMesh): ela é uma peça só, dobrada, e não um
  // arranjo de painéis independentes.
  mailer: { topo: true, fundo: false },
  /*
   * Tampa solta rígida: a mesma silhueta da bandeja — base aberta em cima com
   * a tampa por fora —, então o desenho reaproveita o caminho de painéis dela.
   *
   * O que a rígida tem a mais é INVISÍVEL neste nível: o revestimento é uma
   * camada de papel de décimos de milímetro sobre o cinza, e desenhá-la
   * mudaria a silhueta em menos de um pixel. A diferença que importa está no
   * preço, não na figura — e está coberta por wrap_cost.
   */
  rigid_telescopic: { topo: true, fundo: false },
  // A família livro é desenhada por componente próprio (LivroMesh): a capa é
  // articulada, e não um arranjo de painéis parados. As entradas existem para
  // o mapa continuar exaustivo sobre BoxModel.
  rigid_book: { topo: true, fundo: false },
  rigid_book_flap: { topo: true, fundo: false },
  rigid_magnet: { topo: true, fundo: false },
  rigid_magnet_side: { topo: true, fundo: false },
  rigid_magnet_wrap: { topo: true, fundo: false },
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
  // A caixa livro entra porque a capa é articulada: o slider percorre o giro
  // da tampa em torno da lombada, que é a peça móvel do modelo.
  return (
    hasSeparateLid(model) ||
    model === "drawer" ||
    model === "mailer" ||
    isBook(model)
  );
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

  // A mailer não tem tampa separada, mas a tampa aberta sobe — e sem este ramo
  // a câmera miraria só a base, com a peça saindo pela borda do quadro.
  if (modelo === "mailer") {
    return mailerAlturaMontada(props.heightMm, props.depthMm);
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
 * Caixa livro: capa de painéis articulados + berço colado na contracapa.
 *
 * Componente próprio, como a gaveta e o tubo, porque a peça tem uma
 * ARTICULAÇÃO — não é um arranjo de painéis parados. A tampa e a lombada giram
 * em torno das canaletas, e o slider de abertura percorre esse giro.
 *
 * A geometria lê bookLayout() do motor de preço: os mesmos números que entram
 * na conta desenham a figura. Um painel que exista aqui e não lá — ou o
 * contrário — é uma divergência que nenhum teste do projeto enxerga, porque a
 * paridade só compara PHP com TS, nunca o preço com o desenho.
 */
function LivroMesh({
  boxModel,
  widthMm,
  heightMm,
  depthMm,
  espessuraMm,
  abertura,
  material,
  showEdges,
}: {
  boxModel: BoxModel;
  widthMm: number;
  heightMm: number;
  depthMm: number;
  espessuraMm: number;
  abertura: number;
  material: ReactNode;
  showEdges: boolean;
}) {
  const l = bookLayout(boxModel, widthMm, heightMm, depthMm, espessuraMm);

  /*
   * O enquadramento usa a capa ABERTA, não a caixa fechada.
   *
   * Aberta, a tampa deita para trás e a peça ocupa quase o dobro da
   * profundidade. Normalizar pela caixa fechada faria a tampa sair do
   * enquadramento no meio da animação — o mesmo cuidado que a gaveta toma com
   * o deslize máximo.
   */
  const extensaoAberta = l.capaD + l.lombada + l.capaW;
  const fator =
    SCENE_MAX_UNITS / Math.max(l.capaW, l.bercoH + l.lombada, extensaoAberta, 1);

  const capaW = l.capaW * fator;
  const capaD = l.capaD * fator;
  const lombada = l.lombada * fator;

  // Livro e ímã nunca têm as duas: uma soma resolve os dois casos sem um
  // condicional que teria que ser repetido em cada uso abaixo.
  const abaDianteira = (l.aba + l.magnetFlap) * fator;
  const sideFlap = l.sideFlap * fator;

  const imas = suggestedMagnets(boxModel);

  const bercoW = l.bercoW * fator;
  const bercoD = l.bercoD * fator;
  const bercoH = l.bercoH * fator;

  // Espessura visível com piso, senão o painel some em caixas grandes.
  const t = Math.min(
    Math.max(espessuraMm * fator, MIN_WALL_UNITS),
    bercoW / 4,
    bercoH / 2,
  );

  const tampaRef = useRef<THREE.Group>(null);
  const abaRef = useRef<THREE.Group>(null);
  const lateralEsqRef = useRef<THREE.Group>(null);
  const lateralDirRef = useRef<THREE.Group>(null);
  const suave = useAberturaSuave(abertura);

  useFrame(() => {
    /*
     * A capa gira em torno da canaleta traseira, e a aba em torno da borda da
     * tampa. Rotação e não translação: é uma dobradiça, e mover a peça em
     * linha reta faria a tampa flutuar solta da lombada.
     *
     * O giro da tampa vai a -100°, e não a -90°: capa dura aberta passa um
     * pouco do reto por causa da espessura da lombada, e parar exatamente no
     * reto deixa a peça com cara de papel.
     */
    if (tampaRef.current) {
      tampaRef.current.rotation.x = -(Math.PI * 0.555) * suave.current;
    }

    // A aba abre ANTES da tampa (ela precisa liberar a lateral primeiro), e por
    // isso satura na primeira metade do curso.
    if (abaRef.current) {
      abaRef.current.rotation.x = (Math.PI / 2) * Math.min(suave.current * 2, 1);
    }

    /*
     * As laterais abrem PARA FORA, em sentidos opostos. Elas saem primeiro de
     * todas (saturam a um terço do curso): na peça real é preciso liberá-las
     * antes de levantar a tampa, senão elas travam nas paredes do berço.
     */
    const lateral = (Math.PI / 2) * Math.min(suave.current * 3, 1);

    if (lateralEsqRef.current) lateralEsqRef.current.rotation.z = -lateral;
    if (lateralDirRef.current) lateralDirRef.current.rotation.z = lateral;
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

  // A lombada fica na borda TRASEIRA: é onde o livro abre.
  const zLombada = -capaD / 2 - t / 2;

  return (
    <group position={[0, t / 2, 0]}>
      {/* ── Contracapa: o painel que apoia no chão ─────────────────────── */}
      {painel(capaW, t, capaD, 0, 0, 0, "contracapa")}

      {/* ── Lombada: em pé na borda traseira, unindo as duas capas ─────── */}
      {painel(capaW, lombada, t, 0, lombada / 2, zLombada, "lombada")}

      {/*
       * ── Tampa: gira em torno do topo da lombada ──────────────────────
       *
       * O grupo tem origem NA DOBRADIÇA e o painel é deslocado meia
       * profundidade para frente: assim a rotação acontece na canaleta, e não
       * no centro da tampa (que a faria varrer o interior da caixa).
       */}
      <group ref={tampaRef} position={[0, lombada, zLombada]}>
        {painel(capaW, t, capaD, 0, 0, capaD / 2, "tampa")}

        {/*
         * Aba dianteira: fechamento do livro ou fecho magnético.
         *
         * As duas ocupam a mesma posição e nunca coexistem — o livro tem `aba`,
         * o ímã tem `magnetFlap`. Desenhá-las pelo mesmo grupo mantém uma
         * dobradiça só na cena, que é o que a peça real tem.
         */}
        {abaDianteira > 0 && (
          <group ref={abaRef} position={[0, 0, capaD]}>
            {painel(capaW, t, abaDianteira, 0, 0, abaDianteira / 2, "aba")}

            {/*
             * Os ímãs, na ponta da aba. Desenhados porque são a razão do
             * modelo: sem eles a peça parece uma caixa livro, e o usuário não
             * enxerga o que está pagando na lista de materiais.
             */}
            {imas > 0 &&
              Array.from({ length: imas }, (_, i) => (
                <mesh
                  key={`ima-${i}`}
                  position={[
                    // Espalhados na largura, simétricos em torno do centro.
                    ((i + 0.5) / imas - 0.5) * capaW * 0.6,
                    -t * 0.6,
                    abaDianteira * 0.82,
                  ]}
                  castShadow
                >
                  <cylinderGeometry args={[t * 0.9, t * 0.9, t * 0.5, 16]} />
                  <meshStandardMaterial color="#8A8F98" roughness={0.35} metalness={0.85} />
                </mesh>
              ))}
          </group>
        )}

        {/*
         * Abas laterais: descem pelas bordas da tampa e se recolhem para
         * dentro ao fechar. Giram no sentido oposto uma da outra.
         */}
        {sideFlap > 0 && (
          <>
            <group ref={lateralEsqRef} position={[-capaW / 2, 0, capaD / 2]}>
              {painel(t, sideFlap, capaD, -t / 2, -sideFlap / 2, 0, "aba-lat-esq")}
            </group>
            <group ref={lateralDirRef} position={[capaW / 2, 0, capaD / 2]}>
              {painel(t, sideFlap, capaD, t / 2, -sideFlap / 2, 0, "aba-lat-dir")}
            </group>
          </>
        )}
      </group>

      {/* ── Berço: bandeja de quatro paredes colada sobre a contracapa ─── */}
      <group position={[0, t / 2, 0]}>
        {painel(bercoW, t, bercoD, 0, t / 2, 0, "berco-fundo")}
        {painel(t, bercoH, bercoD, -(bercoW - t) / 2, t + bercoH / 2, 0, "berco-esq")}
        {painel(t, bercoH, bercoD, (bercoW - t) / 2, t + bercoH / 2, 0, "berco-dir")}
        {painel(bercoW - 2 * t, bercoH, t, 0, t + bercoH / 2, (bercoD - t) / 2, "berco-frente")}
        {painel(bercoW - 2 * t, bercoH, t, 0, t + bercoH / 2, -(bercoD - t) / 2, "berco-tras")}
      </group>
    </group>
  );
}

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
 * Inclinação da tampa aberta em relação à horizontal, em radianos (~68°).
 *
 * A mailer é desenhada ABERTA pela mesma razão que a gaveta é desenhada
 * puxada: fechada, ela é um paralelepípedo indistinguível de um RSC, e o
 * usuário não teria como confirmar na tela que escolheu o modelo certo.
 *
 * O valor não escolhe a pose — quem escolhe é MAILER_T_ABERTA, o instante da
 * animação do Blender. Este ângulo só DESCREVE aquela pose, para a câmera
 * saber onde mirar. Mudou o instante, remeça o ângulo.
 */
export const MAILER_ANGULO = 1.19;

/**
 * Altura do conjunto da mailer com a tampa ABERTA, em mm.
 *
 * O braço que sobe não é só a profundidade: a língua continua no plano da
 * tampa e vale a altura da parede, então em caixa alta ela dobra o alcance.
 *
 * É uma estimativa, e serve só para a câmera mirar no meio da peça — o
 * tamanho com que ela é desenhada vem de uma MEDIÇÃO do modelo montado, não
 * daqui (ver MailerModelo). Antes de o modelo entrar, esta conta também
 * escalava a peça, e era ela que deixava a caixa alta sair pelo topo do
 * quadro.
 */
function mailerAlturaMontada(heightMm: number, depthMm: number): number {
  return heightMm + (depthMm + heightMm) * Math.sin(MAILER_ANGULO);
}

/**
 * O modelo da mailer não é desenhado aqui: ele é CARREGADO.
 *
 * `mailer/box-mailer.blend` é a peça que o cliente modelou, e
 * `mailer/export_gltf.py` a converte em glTF (rode o Blender headless; o
 * comando está no cabeçalho do script). Vem tudo junto no arquivo: a
 * hierarquia de vincos, a espessura já aplicada pelo SOLIDIFY e a animação de
 * dobra quadro a quadro.
 *
 * Por que carregar em vez de desenhar: a versão procedural reconstruía a peça
 * a partir das razões da faca e, por mais fiel que a conta ficasse, o
 * resultado na tela não era a caixa dele. Aqui é.
 *
 * O que isso custa, e vale saber antes de mexer:
 *
 *  - o modelo tem UMA medida (a do script), então redimensionar é escalar por
 *    eixo. A silhueta acompanha o que o usuário digita, mas a espessura da
 *    chapa estica junto: numa caixa muito mais estreita que o modelo, a
 *    parede afina na mesma proporção;
 *  - o preço continua vindo de `mailerLayout()`, que parametriza o MESMO
 *    script. Fonte única ainda, só que em dois estágios: o motor recalcula os
 *    painéis para qualquer medida, o desenho mostra a peça assada.
 */
const MAILER_MODELO = "/models/mailer.glb";

/**
 * A caixa que o modelo representa, em mm — é o `interno_mm` que o script
 * reporta, e a referência para escalar até as medidas digitadas (que também
 * são internas, ver mailerLayout).
 */
const MAILER_MODELO_INTERNO = { largura: 285, altura: 80, profundidade: 247 };

/**
 * Os dois instantes da timeline do Blender que o slider interpola, em segundos
 * (a cena roda a 24 fps).
 *
 * Não é a animação inteira: os quadros 1–200 montam a chapa (paredes, rolo,
 * linguetas) e isso a caixa já vem pronta na tela. O trecho útil é o fecho —
 * tampa desce, barbatanas dobram, língua entra —, que é justamente o que o
 * comprador precisa entender do modelo.
 */
const MAILER_T_ABERTA = 220 / 24;
const MAILER_T_FECHADA = 312 / 24;

/** Opacidade dos vincos realçados. */
const MAILER_VINCO_OPACIDADE = 0.19;

function MailerMesh({
  widthMm,
  heightMm,
  depthMm,
  abertura,
  colorHex,
  textureUrl,
  showEdges,
}: {
  widthMm: number;
  heightMm: number;
  depthMm: number;
  abertura: number;
  colorHex: string;
  textureUrl?: string | null;
  showEdges: boolean;
}) {
  /*
   * A textura suspende no carregamento, e hook não pode ser condicional — daí
   * o mesmo desdobramento em dois componentes que TexturedMaterial já faz para
   * os outros modelos.
   */
  if (textureUrl) {
    return (
      <Suspense fallback={<MailerModelo {...{ widthMm, heightMm, depthMm, abertura, colorHex, showEdges }} />}>
        <MailerTexturado
          url={textureUrl}
          {...{ widthMm, heightMm, depthMm, abertura, colorHex, showEdges }}
        />
      </Suspense>
    );
  }

  return <MailerModelo {...{ widthMm, heightMm, depthMm, abertura, colorHex, showEdges }} />;
}

function MailerTexturado({ url, ...resto }: { url: string } & MailerModeloProps) {
  const textura = useTexture(url, (loaded) => {
    for (const item of Array.isArray(loaded) ? loaded : [loaded]) {
      item.wrapS = item.wrapT = THREE.RepeatWrapping;
      item.repeat.set(2, 2);
      item.colorSpace = THREE.SRGBColorSpace;
    }
  });

  return <MailerModelo {...resto} textura={textura} />;
}

interface MailerModeloProps {
  widthMm: number;
  heightMm: number;
  depthMm: number;
  abertura: number;
  colorHex: string;
  showEdges: boolean;
}

function MailerModelo({
  widthMm,
  heightMm,
  depthMm,
  abertura,
  colorHex,
  showEdges,
  textura,
}: MailerModeloProps & { textura?: THREE.Texture }) {
  const { scene, animations } = useGLTF(MAILER_MODELO);

  /*
   * Material próprio, aplicado por cima do que veio do Blender.
   *
   * O arquivo traz o kraft do modelo, mas quem manda na cor é a matéria-prima
   * escolhida na calculadora — senão trocar para papel branco não mudaria nada
   * na tela.
   */
  const material = useMemo(
    () =>
      new THREE.MeshStandardMaterial({
        color: colorHex,
        map: textura ?? null,
        roughness: 0.9,
        metalness: 0,
      }),
    [colorHex, textura],
  );

  /*
   * Clone da cena carregada.
   *
   * O useGLTF guarda o resultado em cache por URL: trocar material ou pose no
   * objeto original vazaria para qualquer outro uso do mesmo arquivo. O clone
   * preserva os nomes dos nós, que é como a animação se liga de volta.
   */
  const peca = useMemo(() => {
    const copia = scene.clone(true);

    copia.traverse((obj) => {
      if (!(obj instanceof THREE.Mesh)) return;

      obj.castShadow = true;
      obj.receiveShadow = true;

      /*
       * Os vincos realçados são filhos da própria malha, criados uma vez e
       * apenas escondidos quando o usuário desliga o realce. Criá-los a cada
       * toggle recompilaria a geometria de arestas de 23 painéis.
       */
      const arestas = new THREE.LineSegments(
        new THREE.EdgesGeometry(obj.geometry, 20),
        new THREE.LineBasicMaterial({
          color: 0x000000,
          transparent: true,
          opacity: MAILER_VINCO_OPACIDADE,
        }),
      );
      arestas.name = "vincos";
      obj.add(arestas);
    });

    return copia;
  }, [scene]);

  // Materiais e visibilidade das arestas seguem os props sem reconstruir nada.
  useMemo(() => {
    peca.traverse((obj) => {
      if (obj instanceof THREE.Mesh && obj.name !== "vincos") obj.material = material;
      if (obj.name === "vincos") obj.visible = showEdges;
    });
  }, [peca, material, showEdges]);

  useEffect(
    () => () => {
      peca.traverse((obj) => {
        if (obj instanceof THREE.LineSegments) {
          obj.geometry.dispose();
          (obj.material as THREE.Material).dispose();
        }
      });
    },
    [peca],
  );

  const grupo = useRef<THREE.Group>(null);
  const { actions, mixer } = useAnimations(animations, grupo);
  const suave = useAberturaSuave(abertura);

  /*
   * Cada vinco virou uma animação própria na exportação (são 22). Todas tocam
   * ao mesmo tempo e nenhuma avança sozinha: quem dita o instante é o slider,
   * pelo mixer.
   */
  /*
   * Efeito de LAYOUT, e antes da medição logo abaixo: o mixer só posiciona os
   * vincos depois que as ações tocam. Como useEffect, a medição rodava antes
   * disso e media a peça na pose de repouso — a chapa PLANA, quase o triplo do
   * comprimento da caixa montada —, e a peça saía desenhada minúscula.
   */
  useLayoutEffect(() => {
    for (const acao of Object.values(actions)) acao?.play();

    return () => {
      for (const acao of Object.values(actions)) acao?.stop();
    };
  }, [actions]);

  useFrame(() => {
    mixer.setTime(
      MAILER_T_FECHADA + (MAILER_T_ABERTA - MAILER_T_FECHADA) * suave.current,
    );
  });

  /*
   * Escala por eixo até as medidas digitadas: o modelo tem UMA medida, e é
   * assim que a silhueta acompanha o que o usuário digita.
   *
   * O preço disso aparece na tampa ABERTA. Fechada, todo painel está alinhado
   * aos eixos e a escala por eixo é exata; aberta, a tampa está girada, e
   * esticar mais um eixo que o outro a CISALHA — numa caixa muito mais alta
   * que o modelo ela sobe bem além do que subiria de verdade. É o custo de
   * carregar um modelo assado em vez de redesenhar a peça a cada medida.
   */
  const escalaEixo: [number, number, number] = [
    widthMm / MAILER_MODELO_INTERNO.largura,
    heightMm / MAILER_MODELO_INTERNO.altura,
    depthMm / MAILER_MODELO_INTERNO.profundidade,
  ];

  /*
   * Por isso o enquadramento MEDE a peça em vez de estimá-la.
   *
   * A conta analítica (altura + braço da tampa) valia para o desenho
   * procedural, onde a tampa girava rígida. Com o modelo esticado ela erra
   * justamente nas proporções extremas, e a peça sai pelo topo do quadro. Uma
   * medição na pose ABERTA resolve os dois casos de uma vez — e é na aberta,
   * nunca na atual, senão a caixa mudaria de tamanho enquanto o usuário a
   * fecha.
   */
  const [modelo, setModelo] = useState<{ eixos: THREE.Vector3; piso: number } | null>(null);

  useLayoutEffect(() => {
    if (!grupo.current) return;

    mixer.setTime(MAILER_T_ABERTA);
    grupo.current.updateWorldMatrix(true, true);

    const caixa = new THREE.Box3().setFromObject(peca);
    const tamanho = caixa.getSize(new THREE.Vector3());

    setModelo({
      // Desfaz a escala já aplicada: o que fica guardado é a peça em unidades
      // do MODELO, eixo a eixo, e o resto da conta é analítico a cada mudança
      // de medida.
      eixos: tamanho.divide(new THREE.Vector3(...escalaEixo)),
      piso: caixa.min.y / escalaEixo[1],
    });
    // A medição não depende das medidas digitadas — só da peça carregada.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [peca, mixer]);

  /*
   * A maior aresta é a do PRODUTO eixo a eixo, não o produto dos máximos:
   * numa caixa alta quem estoura é a altura da peça esticada, e usar
   * max(eixos)×max(escala) superestima — a caixa saía desenhada pequena
   * demais no meio do quadro.
   */
  const ajuste = modelo
    ? SCENE_MAX_UNITS /
      Math.max(
        modelo.eixos.x * escalaEixo[0],
        modelo.eixos.y * escalaEixo[1],
        modelo.eixos.z * escalaEixo[2],
        1e-6,
      )
    : 1;

  const escala: [number, number, number] = [
    escalaEixo[0] * ajuste,
    escalaEixo[1] * ajuste,
    escalaEixo[2] * ajuste,
  ];

  return (
    <group ref={grupo} scale={escala} position={[0, -(modelo?.piso ?? 0) * escala[1], 0]}>
      <primitive object={peca} />
    </group>
  );
}

// O arquivo tem ~165 KB e só é pedido quando alguém escolhe o modelo. Pré-
// carregar evita o vazio de um segundo entre escolher "Mailer" e ver a caixa.
useGLTF.preload(MAILER_MODELO);

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

  if (isBook(boxModel)) {
    return (
      <LivroMesh
        boxModel={boxModel}
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

  if (boxModel === "mailer") {
    return (
      <MailerMesh
        widthMm={widthMm}
        heightMm={heightMm}
        depthMm={depthMm}
        abertura={abertura}
        colorHex={colorHex}
        textureUrl={textureUrl}
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

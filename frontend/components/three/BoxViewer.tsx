"use client";

import { Suspense, useEffect, useRef, useState } from "react";
import * as THREE from "three";
import { Canvas, useFrame, useThree } from "@react-three/fiber";
import {
  OrbitControls,
  Environment,
  ContactShadows,
  Grid,
  Html,
  Lightformer,
} from "@react-three/drei";
import { Rotate3d, ScanSearch } from "lucide-react";
import {
  BoxMesh,
  assemblyHeightUnits,
  assemblyRadiusUnits,
  deltaDeQuadro,
  hasAperture,
  type BoxMeshProps,
} from "./BoxMesh";
import { Slider } from "@/components/ui/slider";
import { isCylindrical } from "@/lib/pricing/engine";
import type { BoxModel } from "@/lib/pricing/types";

export interface BoxViewerProps extends BoxMeshProps {
  /** Rótulo exibido no canto (ex.: nome do material selecionado). */
  materialLabel?: string;
  className?: string;

  /**
   * Avisa quando o Canvas está de pé e desenhando.
   *
   * Existe para a vitrine, onde a caixa entra por cima de um pôster e a troca
   * precisa ser invisível: revelar o 3D cedo demais mostra um retângulo vazio
   * pelos quadros que os shaders levam para compilar.
   *
   * Um `setTimeout` resolveria em quase toda máquina — e falharia justamente nas
   * lentas, que são as que mais precisam do pôster.
   */
  onPronto?: () => void;
}

/**
 * Palco 3D: Canvas, iluminação, chão e controles de órbita.
 *
 * Separado de <BoxMesh /> pela mesma razão que um Controller é separado de um
 * Service: este arquivo cuida da APRESENTAÇÃO (câmera, luz, sombra), o outro
 * cuida do OBJETO. Trocar o modelo de embalagem não deveria exigir mexer na
 * iluminação, e vice-versa.
 */
export function BoxViewer({
  materialLabel,
  className = "",
  onPronto,
  ...boxProps
}: BoxViewerProps) {
  /*
   * A abertura mora AQUI, e não na store do orçamento, de propósito.
   *
   * É estado de câmera, da mesma família do ângulo de órbita e do zoom: não
   * descreve a embalagem, não altera o preço e não deve ser gravado. Colocá-la
   * na store faria cada arrasto sujar a especificação e disparar a
   * sincronização com o servidor — recalculando um preço que não mudou.
   */
  const [abertura, setAbertura] = useState(1);

  /*
   * Pedido de reenquadramento: um contador, e não um booleano.
   *
   * O <CameraFit /> reage à MUDANÇA do valor. Com um booleano, o segundo clique
   * teria que desligar o que o primeiro ligou — e o botão funcionaria uma vez
   * sim, outra não.
   */
  const [reenquadre, setReenquadre] = useState(0);

  const modelo = boxProps.boxModel ?? "rsc";
  const temAbertura = hasAperture(modelo);

  return (
    <div className={`palco-3d relative h-full w-full ${className}`}>
      <Canvas
        shadows
        // Redesenha só quando algo pede (interação, ou os `invalidate()` das
        // animações de abertura/câmera) em vez de um loop de requestAnimationFrame
        // contínuo. Parado, este preview não tem nada mudando quadro a quadro —
        // o loop "always" mantinha a aba ocupada renderizando o mesmo frame sem
        // parar, disputando a thread principal até com um clique para sair da tela.
        frameloop="demand"
        // Posição inicial em 3/4: mostra três faces de uma vez, deixando as
        // proporções evidentes sem que o usuário precise girar nada. A
        // DISTÂNCIA é ajustada logo depois pelo <CameraFit />, conforme o
        // volume da peça e a largura da coluna.
        camera={{ position: POSICAO_INICIAL, fov: 42 }}
        // dpr limitado a 2: acima disso o custo de renderização cresce sem
        // ganho visual perceptível, e o preview precisa ser fluido enquanto
        // o usuário digita no formulário ao lado.
        dpr={[1, 2]}
        /*
         * `preserveDrawingBuffer` mantém o buffer legível depois de composto,
         * que é o que permite exportar a imagem do preview (canvas.toDataURL).
         * Custa memória de vídeo e não é grátis — está aqui porque a captura
         * do modelo é requisito da tela, não por precaução.
         *
         * Tone mapping NEUTRO, e não o ACES que o R3F liga por padrão. O ACES
         * é curva de cinema: dessatura e escurece os tons médios, e o kraft
         * cadastrado como #C8A06A chegava à tela acinzentado. Aqui a cor É
         * dado — o usuário escolheu um material e precisa reconhecê-lo. O
         * neutro só comprime os brilhos estourados e deixa o resto como está.
         */
        gl={{
          antialias: true,
          alpha: true,
          preserveDrawingBuffer: true,
          toneMapping: THREE.NeutralToneMapping,
        }}
        /*
         * `onCreated` dispara com o contexto WebGL pronto, mas ainda ANTES do
         * primeiro quadro pintado. Os dois `requestAnimationFrame` esperam esse
         * quadro acontecer — sem eles, quem escuta revelaria a cena um piscar
         * cedo demais e veria o retângulo vazio que o pôster existe para cobrir.
         */
        onCreated={() => {
          if (!onPronto) return;

          requestAnimationFrame(() => requestAnimationFrame(onPronto));
        }}
      >
        <Suspense fallback={<LoadingIndicator />}>
          {/* ── Iluminação ───────────────────────────────────────────────
              Três papéis, como num estúdio de produto: o céu preenche (e
              devolve um tom quente por baixo, que é o que o chão claro faria),
              a principal modela o volume e projeta a sombra, e o contra-luz
              destaca a aresta de trás do fundo. */}
          <hemisphereLight args={["#ffffff", "#efe6d9", 0.8]} />
          <directionalLight
            position={[4.5, 8, 5.5]}
            intensity={1.2}
            castShadow
            shadow-mapSize={[1024, 1024]}
            shadow-bias={-0.0003}
            shadow-normalBias={0.02}
          >
            {/* O volume de sombra abraça a peça (que nunca passa de ~2
                unidades) em vez dos ±5 padrão: os mesmos 1024 texels cobrem
                metade da área, e a borda da sombra fica duas vezes mais
                nítida sem dobrar o mapa — que custaria quatro vezes mais em
                toda máquina sem placa de vídeo dedicada. */}
            <orthographicCamera attach="shadow-camera" args={[-3.6, 3.6, 3.6, -3.6, 0.5, 24]} />
          </directionalLight>
          <directionalLight position={[-5, 3.5, -4.5]} intensity={0.5} />

          {/*
            Ambiente LOCAL, feito de painéis de luz — sem HDRI.

            O preset "city" baixava um .hdr de um CDN de terceiros a cada
            visita: sem rede (ou com o CDN fora) o Suspense não resolvia e a
            calculadora ficava em "Carregando modelo…" para sempre, por causa
            de um reflexo. Estes softboxes são renderizados uma vez num cubemap
            pequeno e dão o que importa para papel fosco: um degradê suave
            atravessando cada face, que é o que separa uma chapa de verdade de
            um polígono pintado de uma cor só.
          */}
          <Environment resolution={128} environmentIntensity={0.65}>
            <Lightformer form="rect" intensity={2.4} position={[0, 6, 0]} rotation-x={Math.PI / 2} scale={[10, 10, 1]} />
            <Lightformer form="rect" intensity={1.3} position={[-6, 2.5, 2]} rotation-y={Math.PI / 2} scale={[9, 5, 1]} />
            <Lightformer form="rect" intensity={0.9} color="#fff1dc" position={[6, 2, -2]} rotation-y={-Math.PI / 2} scale={[9, 4, 1]} />
            <Lightformer form="rect" intensity={0.7} position={[0, 2, 7]} rotation-y={Math.PI} scale={[10, 4, 1]} />
          </Environment>

          {/* ── Cena ───────────────────────────────────────────────────── */}
          {/* Modelo sem peça móvel ignora a abertura e fica sempre montado. */}
          <BoxMesh {...boxProps} aperture={temAbertura ? abertura : 1} />

          {/*
            O chão, em duas sombras que fazem trabalhos diferentes.

            A projetada (shadowMaterial) vem da luz principal e dá DIREÇÃO: a
            tampa aberta desenha a própria silhueta no piso, e é por ela que se
            lê a altura de uma peça que flutua. A de contato é difusa e fica
            colada na base: é a âncora que impede a caixa de parecer solta no
            vazio. Só uma das duas deixava a peça ou sem peso, ou sem luz.
          */}
          <mesh rotation-x={-Math.PI / 2} position={[0, -0.002, 0]} receiveShadow>
            <planeGeometry args={[16, 16]} />
            <shadowMaterial transparent opacity={0.13} />
          </mesh>

          <ContactShadows
            position={[0, 0, 0]}
            opacity={0.32}
            scale={10}
            blur={2.2}
            // Só o que está RENTE ao chão conta. Com alcance alto, a tampa
            // aberta um palmo acima entrava na conta e desenhava no piso uma
            // mancha difusa do tamanho dela — sombra de contato de uma coisa
            // que não está em contato. Quem projeta a tampa é a luz principal.
            far={0.9}
            resolution={256}
            color="#1b2636"
          />

          {/* Grade de referência de escala.
              Contraste deliberadamente baixo e fade curto: a grade existe para
              dar noção de profundidade e escala, não para disputar atenção com
              a embalagem — que é o objeto que o usuário veio avaliar. */}
          <Grid
            position={[0, -0.004, 0]}
            args={[20, 20]}
            cellSize={0.25}
            cellThickness={0.5}
            cellColor="#d5dde8"
            sectionSize={1}
            sectionThickness={0.8}
            sectionColor="#aab8ca"
            fadeDistance={9}
            fadeStrength={2.4}
            infiniteGrid
            followCamera={false}
          />

          {/* Enquadramento: aproxima ou afasta conforme o volume da peça e o
              tamanho do quadro. Dentro do Canvas porque precisa da câmera. */}
          <CameraFit
            raio={assemblyRadiusUnits(boxProps)}
            alvoY={assemblyHeightUnits(boxProps) / 2}
            pedido={reenquadre}
          />

          {/* ── Interação ──────────────────────────────────────────────── */}
          <OrbitControls
            makeDefault
            enablePan={false}
            enableDamping
            dampingFactor={0.08}
            minDistance={DISTANCIA_MINIMA}
            maxDistance={DISTANCIA_MAXIMA}
            // Trava abaixo do horizonte: olhar a caixa "por baixo do chão"
            // não informa nada e desorienta o usuário.
            maxPolarAngle={Math.PI / 2.05}
            /**
             * Mira no centro geométrico do que está desenhado.
             *
             * Um alvo fixo descentraliza assim que a tampa muda de altura —
             * e com tampa alta o conjunto saía pela borda do quadro. Derivar
             * do próprio conjunto mantém o enquadramento correto para
             * qualquer combinação de medidas.
             */
            target={[0, assemblyHeightUnits(boxProps) / 2, 0]}
          />
        </Suspense>
      </Canvas>

      {/* ── HUD (DOM sobre o Canvas) ─────────────────────────────────────
          Renderizado em HTML comum, não em <Text> do three: é texto puro,
          e o DOM entrega tipografia nítida e acessível de graça. */}
      <div className="pointer-events-none absolute inset-x-0 bottom-0 flex flex-col gap-3 p-4">
        {temAbertura && (
          <ApertureControl model={modelo} value={abertura} onChange={setAbertura} />
        )}

        <div className="flex items-end justify-between gap-2">
          <DimensionBadge {...boxProps} />
          {materialLabel && (
            <span className="flex min-w-0 items-center gap-1.5 rounded-full border border-border/70 bg-background/85 py-1 pl-2 pr-2.5 text-xs font-medium text-muted-foreground shadow-xs backdrop-blur">
              {/* A amostra é a cor que o cadastro guarda — a mesma que pinta a
                  peça. Ao lado do nome, confirma que o que está na tela é o
                  material escolhido, e não um padrão de fábrica. */}
              {boxProps.colorHex && (
                <span
                  aria-hidden
                  className="size-2.5 shrink-0 rounded-full ring-1 ring-foreground/15"
                  style={{ backgroundColor: boxProps.colorHex }}
                />
              )}
              <span className="truncate">{materialLabel}</span>
            </span>
          )}
        </div>
      </div>

      <div className="pointer-events-none absolute inset-x-4 top-4 flex items-start justify-between gap-2">
        {/* `pointer-events-auto` só no botão, pela mesma razão do slider: o
            resto da faixa precisa deixar o arrasto chegar à órbita. */}
        <button
          type="button"
          onClick={() => setReenquadre((n) => n + 1)}
          className="pointer-events-auto flex items-center gap-1.5 rounded-full border border-border/70 bg-background/85 px-2.5 py-1 text-[11px] font-medium text-muted-foreground shadow-xs backdrop-blur transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >
          <ScanSearch className="size-3.5 text-brand-strong" aria-hidden />
          Recentralizar
        </button>

        <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
          <Rotate3d className="size-3.5" aria-hidden />
          Arraste para girar · Scroll para zoom
        </span>
      </div>
    </div>
  );
}

/**
 * De onde a câmera olha ao abrir — e para onde volta em "Recentralizar".
 *
 * Só a DIREÇÃO importa: a distância é do <CameraFit />.
 */
const POSICAO_INICIAL: [number, number, number] = [3.2, 2.4, 3.6];

/** Limites de zoom manual, em unidades de cena. */
const DISTANCIA_MINIMA = 1.5;
const DISTANCIA_MAXIMA = 10;

/** Folga entre a esfera envolvente e a borda do quadro. 1 = peça colada nas bordas. */
const MARGEM_DE_QUADRO = 1.12;

/**
 * Enquadra a peça pela esfera que a envolve.
 *
 * A cena já normaliza o TAMANHO — a maior aresta sempre ocupa duas unidades —,
 * mas não a PROPORÇÃO: uma coluna estreita ocupa menos de um terço do volume de
 * um cubo, e com a câmera parada ela aparecia pequena no meio de um vazio.
 * Aqui a distância sai da trigonometria do campo de visão, considerando também
 * o campo HORIZONTAL: numa coluna estreita de layout, é a largura que corta a
 * peça primeiro, e enquadrar só pela vertical deixaria a caixa saindo pelos
 * lados.
 *
 * Duas garantias de não atrapalhar quem está olhando: a transição é
 * interpolada (a peça não salta de lugar a cada tecla digitada), e ela PARA ao
 * chegar — depois disso o zoom é do usuário, e ficar corrigindo a distância a
 * cada quadro seria arrancar o controle da mão dele.
 */
function CameraFit({
  raio,
  alvoY,
  pedido,
}: {
  raio: number;
  alvoY: number;
  /** Contador de pedidos de "Recentralizar"; cada mudança devolve o ângulo inicial. */
  pedido: number;
}) {
  const { camera, size, invalidate } = useThree();

  const alvo = useRef<number | null>(null);
  const centro = useRef(new THREE.Vector3());
  const enquadrando = useRef(false);

  /*
   * Recentralizar devolve o ÂNGULO; a distância é o efeito abaixo que resolve.
   *
   * O primeiro valor é ignorado: na montagem a câmera já nasce na posição
   * inicial, e reposicioná-la ali só desperdiçaria um quadro.
   */
  const ultimoPedido = useRef(pedido);

  useEffect(() => {
    if (ultimoPedido.current === pedido) return;
    ultimoPedido.current = pedido;

    const distancia = camera.position.distanceTo(centro.current.set(0, alvoY, 0));

    camera.position
      .set(...POSICAO_INICIAL)
      .setLength(distancia || DISTANCIA_MINIMA)
      .add(centro.current);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- só o pedido dispara; alvoY é lido no instante do clique.
  }, [pedido]);

  useEffect(() => {
    const perspectiva = camera as THREE.PerspectiveCamera;

    const fovVertical = (perspectiva.fov * Math.PI) / 180;
    const fovHorizontal =
      2 * Math.atan(Math.tan(fovVertical / 2) * (size.width / size.height));

    // O menor dos dois campos é o que corta a peça primeiro.
    const campo = Math.min(fovVertical, fovHorizontal);

    const distancia = (raio / Math.sin(campo / 2)) * MARGEM_DE_QUADRO;

    alvo.current = Math.min(Math.max(distancia, DISTANCIA_MINIMA), DISTANCIA_MAXIMA);
    // Em frameloop="demand", sem este pedido o useFrame abaixo só veria o
    // alvo novo na próxima interação do usuário.
    invalidate();
  }, [raio, camera, size.width, size.height, invalidate, pedido]);

  useFrame((state, delta) => {
    if (alvo.current === null) {
      enquadrando.current = false;

      return;
    }

    centro.current.set(0, alvoY, 0);

    const atual = camera.position.distanceTo(centro.current);
    const proxima = THREE.MathUtils.damp(
      atual,
      alvo.current,
      6,
      deltaDeQuadro(delta, enquadrando.current),
    );

    // Chegou: solta o controle. Sem isto, cada quadro reimporia a distância e
    // o scroll do usuário voltaria sozinho para onde a câmera "queria" estar.
    if (Math.abs(proxima - alvo.current) < 0.005) {
      alvo.current = null;
      enquadrando.current = false;
    } else {
      // Em frameloop="demand" o quadro seguinte só existe se for pedido.
      enquadrando.current = true;
      state.invalidate();
    }

    camera.position
      .sub(centro.current)
      .setLength(proxima || DISTANCIA_MINIMA)
      .add(centro.current);
  });

  return null;
}

/**
 * Nome da peça que se move, por modelo.
 *
 * Rotular tudo como "Abertura" seria genérico demais: numa gaveta o que se
 * move não é uma tampa, e chamar pelo nome certo dispensa o usuário de
 * descobrir o que o controle faz arrastando.
 */
const PECA_MOVEL: Partial<Record<BoxModel, string>> = {
  tray: "Tampa",
  tube: "Tampa",
  drawer: "Gaveta",
  mailer: "Tampa",
};

/**
 * Slider de abertura, sobreposto ao Canvas.
 *
 * `pointer-events-auto` aparece SÓ aqui: o resto do HUD precisa deixar o
 * gesto atravessar até o OrbitControls, mas este controle tem que capturá-lo
 * — senão arrastar o slider giraria a câmera junto.
 */
function ApertureControl({
  model,
  value,
  onChange,
}: {
  model: BoxModel;
  value: number;
  onChange: (v: number) => void;
}) {
  const peca = PECA_MOVEL[model] ?? "Abertura";
  const percentual = Math.round(value * 100);

  return (
    <div
      role="group"
      aria-label={`Abertura: ${peca}`}
      className="pointer-events-auto mx-auto flex w-full max-w-xs items-center gap-3 rounded-full border border-border/70 bg-background/85 px-4 py-2 shadow-sm backdrop-blur"
    >
      <span className="text-xs font-medium text-muted-foreground">{peca}</span>

      <Slider
        value={[percentual]}
        onValueChange={([v]) => onChange(v / 100)}
        min={0}
        max={100}
        step={1}
        className="flex-1"
      />

      {/* tabular-nums: sem isso o número dança de largura durante o arrasto. */}
      <span className="w-9 text-right font-mono text-xs tabular-nums text-muted-foreground">
        {percentual}%
      </span>
    </div>
  );
}

function DimensionBadge({
  widthMm,
  heightMm,
  depthMm,
  boxModel,
}: Pick<BoxMeshProps, "widthMm" | "heightMm" | "depthMm" | "boxModel">) {
  // Num cilindro a profundidade não é uma medida do produto — é o próprio
  // diâmetro repetido. Exibi-la sugeriria um grau de liberdade que não existe.
  const legenda = isCylindrical(boxModel ?? "rsc")
    ? `Ø${widthMm} × ${heightMm} mm`
    : `${widthMm} × ${heightMm} × ${depthMm} mm`;

  return (
    <span className="shrink-0 rounded-full border border-border/70 bg-background/85 px-2.5 py-1 font-mono text-xs text-muted-foreground shadow-xs backdrop-blur">
      {legenda}
    </span>
  );
}

/** Exibido enquanto texturas/HDRI carregam. */
function LoadingIndicator() {
  return (
    <Html center>
      <span className="text-xs text-muted-foreground">Carregando modelo…</span>
    </Html>
  );
}

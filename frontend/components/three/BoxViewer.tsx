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
} from "@react-three/drei";
import {
  BoxMesh,
  assemblyHeightUnits,
  assemblyRadiusUnits,
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

  const modelo = boxProps.boxModel ?? "rsc";
  const temAbertura = hasAperture(modelo);

  return (
    <div className={`relative h-full w-full ${className}`}>
      <Canvas
        shadows
        // Posição inicial em 3/4: mostra três faces de uma vez, deixando as
        // proporções evidentes sem que o usuário precise girar nada. A
        // DISTÂNCIA é ajustada logo depois pelo <CameraFit />, conforme o
        // volume da peça e a largura da coluna.
        camera={{ position: [3.2, 2.4, 3.6], fov: 42 }}
        // dpr limitado a 2: acima disso o custo de renderização cresce sem
        // ganho visual perceptível, e o preview precisa ser fluido enquanto
        // o usuário digita no formulário ao lado.
        dpr={[1, 2]}
        /*
         * `preserveDrawingBuffer` mantém o buffer legível depois de composto,
         * que é o que permite exportar a imagem do preview (canvas.toDataURL).
         * Custa memória de vídeo e não é grátis — está aqui porque a captura
         * do modelo é requisito da tela, não por precaução.
         */
        gl={{ antialias: true, alpha: true, preserveDrawingBuffer: true }}
      >
        <Suspense fallback={<LoadingIndicator />}>
          {/* ── Iluminação ─────────────────────────────────────────────── */}
          <ambientLight intensity={0.6} />
          <directionalLight
            position={[5, 10, 5]}
            intensity={0.8}
            castShadow
            shadow-mapSize={[1024, 1024]}
            shadow-bias={-0.0004}
          />
          {/* Preenchimento oposto à principal: sem ele a face em sombra vira
              um bloco preto ilegível, e é nela que o usuário confere a
              profundidade da caixa. */}
          <pointLight position={[-5, -5, -5]} intensity={0.3} />

          {/* HDRI leve apenas para reflexos — sem background, para que o
              Canvas herde o fundo da página (tema claro/escuro). */}
          <Environment preset="city" />

          {/* ── Cena ───────────────────────────────────────────────────── */}
          {/* Modelo sem peça móvel ignora a abertura e fica sempre montado. */}
          <BoxMesh {...boxProps} aperture={temAbertura ? abertura : 1} />

          {/* Sombra de contato: âncora visual que impede a peça de parecer
              flutuando no vazio. */}
          <ContactShadows
            position={[0, 0, 0]}
            opacity={0.35}
            scale={12}
            blur={2.4}
            far={4}
          />

          {/* Grade de referência de escala.
              Contraste deliberadamente baixo e fade curto: a grade existe para
              dar noção de profundidade e escala, não para disputar atenção com
              a embalagem — que é o objeto que o usuário veio avaliar. */}
          <Grid
            args={[20, 20]}
            cellSize={0.25}
            cellThickness={0.4}
            cellColor="#cbd5e1"
            sectionSize={1}
            sectionThickness={0.7}
            sectionColor="#94a3b8"
            fadeDistance={11}
            fadeStrength={2}
            infiniteGrid
            followCamera={false}
          />

          {/* Enquadramento: aproxima ou afasta conforme o volume da peça e o
              tamanho do quadro. Dentro do Canvas porque precisa da câmera. */}
          <CameraFit
            raio={assemblyRadiusUnits(boxProps)}
            alvoY={assemblyHeightUnits(boxProps) / 2}
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

        <div className="flex items-end justify-between">
          <DimensionBadge {...boxProps} />
          {materialLabel && (
            <span className="rounded-md bg-background/80 px-2.5 py-1 text-xs font-medium text-muted-foreground backdrop-blur">
              {materialLabel}
            </span>
          )}
        </div>
      </div>

      <span className="pointer-events-none absolute right-4 top-4 text-[11px] text-muted-foreground">
        Arraste para girar · Scroll para zoom
      </span>
    </div>
  );
}

/** Limites de zoom manual, em unidades de cena. */
const DISTANCIA_MINIMA = 1.5;
const DISTANCIA_MAXIMA = 10;

/** Folga entre a esfera envolvente e a borda do quadro. 1 = peça colada nas bordas. */
const MARGEM_DE_QUADRO = 1.25;

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
function CameraFit({ raio, alvoY }: { raio: number; alvoY: number }) {
  const { camera, size } = useThree();

  const alvo = useRef<number | null>(null);
  const centro = useRef(new THREE.Vector3());

  useEffect(() => {
    const perspectiva = camera as THREE.PerspectiveCamera;

    const fovVertical = (perspectiva.fov * Math.PI) / 180;
    const fovHorizontal =
      2 * Math.atan(Math.tan(fovVertical / 2) * (size.width / size.height));

    // O menor dos dois campos é o que corta a peça primeiro.
    const campo = Math.min(fovVertical, fovHorizontal);

    const distancia = (raio / Math.sin(campo / 2)) * MARGEM_DE_QUADRO;

    alvo.current = Math.min(Math.max(distancia, DISTANCIA_MINIMA), DISTANCIA_MAXIMA);
  }, [raio, camera, size.width, size.height]);

  useFrame((_, delta) => {
    if (alvo.current === null) return;

    centro.current.set(0, alvoY, 0);

    const atual = camera.position.distanceTo(centro.current);
    const proxima = THREE.MathUtils.damp(atual, alvo.current, 6, delta);

    // Chegou: solta o controle. Sem isto, cada quadro reimporia a distância e
    // o scroll do usuário voltaria sozinho para onde a câmera "queria" estar.
    if (Math.abs(proxima - alvo.current) < 0.005) {
      alvo.current = null;
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
      className="pointer-events-auto mx-auto flex w-full max-w-xs items-center gap-3 rounded-lg bg-background/80 px-3 py-2 shadow-sm backdrop-blur"
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
    <span className="rounded-md bg-background/80 px-2.5 py-1 font-mono text-xs text-muted-foreground backdrop-blur">
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

"use client";

import { Suspense } from "react";
import { Canvas } from "@react-three/fiber";
import {
  OrbitControls,
  Environment,
  ContactShadows,
  Grid,
  Html,
} from "@react-three/drei";
import { BoxMesh, assemblyHeightUnits, type BoxMeshProps } from "./BoxMesh";
import { isCylindrical } from "@/lib/pricing/engine";

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
  return (
    <div className={`relative h-full w-full ${className}`}>
      <Canvas
        shadows
        // Posição inicial em 3/4: mostra três faces de uma vez, deixando as
        // proporções evidentes sem que o usuário precise girar nada.
        camera={{ position: [3.2, 2.4, 3.6], fov: 42 }}
        // dpr limitado a 2: acima disso o custo de renderização cresce sem
        // ganho visual perceptível, e o preview precisa ser fluido enquanto
        // o usuário digita no formulário ao lado.
        dpr={[1, 2]}
        gl={{ antialias: true, alpha: true }}
      >
        <Suspense fallback={<LoadingIndicator />}>
          {/* ── Iluminação ─────────────────────────────────────────────── */}
          <ambientLight intensity={0.55} />
          <directionalLight
            position={[5, 8, 5]}
            intensity={1.6}
            castShadow
            shadow-mapSize={[1024, 1024]}
            shadow-bias={-0.0004}
          />
          {/* Luz de preenchimento fria, oposta à principal: evita que a face
              em sombra vire um bloco preto ilegível. */}
          <directionalLight position={[-4, 3, -4]} intensity={0.35} color="#bcd4ff" />

          {/* HDRI leve apenas para reflexos — sem background, para que o
              Canvas herde o fundo da página (tema claro/escuro). */}
          <Environment preset="city" />

          {/* ── Cena ───────────────────────────────────────────────────── */}
          <BoxMesh {...boxProps} />

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

          {/* ── Interação ──────────────────────────────────────────────── */}
          <OrbitControls
            makeDefault
            enablePan={false}
            enableDamping
            dampingFactor={0.08}
            minDistance={1.8}
            maxDistance={12}
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
      <div className="pointer-events-none absolute inset-x-0 bottom-0 flex items-end justify-between p-4">
        <DimensionBadge {...boxProps} />
        {materialLabel && (
          <span className="rounded-md bg-background/80 px-2.5 py-1 text-xs font-medium text-muted-foreground backdrop-blur">
            {materialLabel}
          </span>
        )}
      </div>

      <span className="pointer-events-none absolute right-4 top-4 text-[11px] text-muted-foreground">
        Arraste para girar · Scroll para zoom
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

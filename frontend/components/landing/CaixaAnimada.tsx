import { Package } from "lucide-react";

import { cn } from "@/lib/utils";

import estilos from "./CaixaAnimada.module.css";

/**
 * A caixa do herói — desenhada em CSS, de propósito.
 *
 * A tentação óbvia era montar aqui o visualizador da calculadora. Ele custa
 * mais de meio megabyte de JavaScript (three + fiber + drei), e o herói é a
 * única parte da página que TEM que estar pronta antes de o visitante decidir
 * se fica. Seis `div` com `transform` chegam no primeiro quadro, animam no
 * compositor e pesam menos que o ícone do cabeçalho.
 *
 * Não é o produto — é a ilustração dele. O produto de verdade aparece ao lado,
 * no plano de corte e no cartão de preço, que reproduzem telas que existem. A
 * caixa só responde, sem palavras, à pergunta "isto é sobre o quê?".
 *
 * É decoração: `aria-hidden`. As medidas das cotas repetem a legenda do cartão
 * de preço, que é texto de verdade e é onde o leitor de tela as encontra.
 */
export function CaixaAnimada({ className }: { className?: string }) {
  return (
    <div className={cn(estilos.palco, className)} aria-hidden>
      <span className={cn(estilos.cota, estilos.cotaTopo)}>
        <strong>200 × 150</strong> mm
      </span>
      <span className={cn(estilos.cota, estilos.cotaBase)}>
        alt. <strong>80</strong> mm
      </span>

      <div className={estilos.cena}>
        <div className={estilos.caixa}>
          <div className={cn(estilos.anel, estilos.anelFora)} />
          <div className={cn(estilos.anel, estilos.anelDentro)} />
          <div className={estilos.sombra} />

          <div className={cn(estilos.face, estilos.fundo)} />
          <div className={cn(estilos.face, estilos.tras)} />
          <div className={cn(estilos.face, estilos.esquerda)} />
          <div className={cn(estilos.face, estilos.direita)} />

          <div className={cn(estilos.face, estilos.frente)}>
            <span className={estilos.selo}>
              <Package />
            </span>
          </div>

          <div className={estilos.tampa}>
            <div className={estilos.lingua} />
          </div>
        </div>
      </div>
    </div>
  );
}

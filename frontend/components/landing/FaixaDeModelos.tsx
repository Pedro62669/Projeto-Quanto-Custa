import { Box } from "lucide-react";

import { ROTULO_MODELO } from "@/lib/rotulos";

/**
 * Os modelos que o sistema desenha e precifica — lidos de `ROTULO_MODELO`.
 *
 * A lista NÃO é escrita aqui. É a mesma que rotula os orçamentos dentro do
 * sistema: o dia em que entrar um modelo novo na calculadora, ele aparece nesta
 * faixa sem ninguém lembrar que a página de vendas existe — e o dia em que um
 * sair, a página para de prometê-lo.
 *
 * O modelo livre fica de fora da contagem e da esteira: ele não é "mais um
 * modelo pronto", é a saída para a peça que não cabe em nenhum, e tem a própria
 * frase logo abaixo.
 */
const MODELOS = Object.entries(ROTULO_MODELO)
  .filter(([chave]) => chave !== "free")
  .map(([, rotulo]) => rotulo);

/** Quantos modelos prontos existem — para quem quiser citar o número. */
export const TOTAL_DE_MODELOS = MODELOS.length;

/**
 * A esteira: os modelos passando, devagar, numa faixa sob o herói.
 *
 * O truque do laço sem emenda é desenhar a lista DUAS vezes e andar exatamente
 * meia largura: quando a primeira cópia sai inteira pela esquerda, a segunda
 * está no lugar exato em que a primeira começou, e o salto de volta ao início é
 * invisível.
 *
 * A segunda cópia é `aria-hidden`: para o leitor de tela a lista existe uma vez.
 * E a faixa para quando o ponteiro passa por cima (`.esteira:hover`), que é o
 * mínimo que se deve a quem quer ler um nome que está andando.
 */
export function FaixaDeModelos() {
  return (
    <div
      className="relative overflow-hidden border-y border-border/60 bg-card/60 py-4"
      // A máscara apaga as duas pontas: os nomes surgem e somem em vez de
      // serem cortados a seco na borda da tela.
      style={{
        maskImage:
          "linear-gradient(to right, transparent, black 12%, black 88%, transparent)",
      }}
    >
      <div className="esteira flex w-max gap-3">
        <Lista />
        <Lista oculta />
      </div>
    </div>
  );
}

function Lista({ oculta = false }: { oculta?: boolean }) {
  return (
    <ul className="flex shrink-0 gap-3 pr-0" aria-hidden={oculta || undefined}>
      {MODELOS.map((modelo) => (
        <li
          key={modelo}
          className="flex shrink-0 items-center gap-2 rounded-full border border-border bg-background px-4 py-1.5 text-sm whitespace-nowrap text-muted-foreground"
        >
          <Box className="size-3.5 text-kraft" aria-hidden />
          {modelo}
        </li>
      ))}
    </ul>
  );
}

/**
 * Os botões de ação da vitrine.
 *
 * Não usam `<Button>`: os tamanhos do sistema vão até 36px de altura, calibrados
 * para telas densas de trabalho, onde cada linha disputa espaço com uma tabela.
 * Uma página de vendas tem a economia oposta — poucos alvos, grandes, cercados
 * de espaço vazio. Reaproveitar o componente exigiria vencê-lo com classes até
 * não restar nada dele.
 *
 * Em módulo próprio (sem "use client") para que o cabeçalho interativo e as
 * seções renderizadas no servidor leiam a MESMA string.
 *
 * O principal responde ao ponteiro de duas formas: sobe um pixel e meio (a
 * sombra cresce junto, que é o que faz o olho ler "saiu do papel") e um brilho
 * o atravessa — a classe `brilho`, em globals.css. As duas são `transform`, e
 * por isso não empurram nada em volta.
 */
export const ESTILO_CTA =
  "brilho inline-flex items-center justify-center gap-2 rounded-xl bg-primary font-medium text-primary-foreground shadow-elevado transition-[translate,box-shadow,background-color] duration-200 hover:-translate-y-0.5 hover:bg-primary/92 active:translate-y-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";

export const ESTILO_CTA_SECUNDARIO =
  "inline-flex items-center justify-center gap-2 rounded-xl border border-border bg-card/80 font-medium text-foreground shadow-xs backdrop-blur transition-[translate,background-color,border-color] duration-200 hover:-translate-y-0.5 hover:border-foreground/25 hover:bg-card active:translate-y-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";

/**
 * O botão principal sobre fundo ESCURO (o fechamento da página).
 *
 * Lá o fundo já é a tinta do primário, então o botão inverte: superfície clara,
 * texto escuro. Usar `ESTILO_CTA` ali daria um botão da cor da seção, visível
 * só pela sombra.
 */
export const ESTILO_CTA_INVERTIDO =
  "brilho inline-flex items-center justify-center gap-2 rounded-xl bg-background font-medium text-foreground shadow-elevado transition-[translate,background-color] duration-200 hover:-translate-y-0.5 hover:bg-white active:translate-y-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-on-inverted";

/** A etiqueta acima do título de cada seção. */
export const ESTILO_SOBRETITULO =
  "font-mono text-xs font-medium uppercase tracking-[0.18em] text-brand";

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
 */
export const ESTILO_CTA =
  "inline-flex items-center justify-center gap-2 rounded-lg bg-primary font-medium text-primary-foreground transition-colors hover:bg-primary/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";

export const ESTILO_CTA_SECUNDARIO =
  "inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-background font-medium text-foreground transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";

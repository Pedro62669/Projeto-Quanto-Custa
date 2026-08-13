"use client";

/**
 * Cabeçalho de página.
 *
 * Título, uma linha explicando o que a tela faz, e a ação primária à direita.
 * A frase de apoio não é enfeite: metade destas telas configura número que
 * entra no preço, e quem abre "Custos" pela primeira vez precisa saber o que
 * está prestes a mexer.
 */
export function PageHeader({
  title,
  description,
  actions,
}: {
  title: string;
  description?: string;
  actions?: React.ReactNode;
}) {
  return (
    <header className="flex flex-wrap items-start justify-between gap-3">
      <div className="min-w-0">
        <h1 className="text-xl font-semibold">{title}</h1>
        {description && (
          <p className="mt-0.5 max-w-2xl text-sm text-muted-foreground">{description}</p>
        )}
      </div>

      {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
    </header>
  );
}

import { formatCurrency } from "@/lib/pricing/engine";

/**
 * O resumo de preço, como ele sai da calculadora.
 *
 * Reproduz o `PriceSummary` do sistema — mesma barra de composição, mesmos
 * grupos de custo. A página de vendas mostra o produto, não um desenho
 * inspirado nele: quem se cadastrar vai encontrar exatamente esta tela.
 *
 * Os valores são de exemplo, mas fecham: os cinco itens somam o preço unitário,
 * e o unitário vezes a quantidade dá o total. As porcentagens são calculadas
 * aqui a partir dos valores, e não escritas — foi assim que a barra deixou de
 * poder mostrar 41% de material ao lado de um número que não é 41% do preço.
 */

const QUANTIDADE = 250;

/*
 * A escala da barra, do custo mais pesado ao lucro.
 *
 * Era cinza em cinco opacidades. Em azul a leitura é a mesma — a ordem continua
 * sendo dada pela intensidade, não pelo matiz — e a barra passa a ser a peça de
 * marca da página, que é onde o olho para primeiro.
 *
 * O degradê é por OPACIDADE e não por cinco azuis escritos à mão: assim ele
 * acompanha `--brand` se a cor da empresa mudar, e continua legível nos dois
 * temas, porque o que varia é quanto do fundo aparece por baixo.
 */
const COMPOSICAO = [
  { rotulo: "Material", valor: 3.45, tom: "bg-brand" },
  { rotulo: "Mão de obra", valor: 2.27, tom: "bg-brand/75" },
  { rotulo: "Hora-empresa", valor: 1.01, tom: "bg-brand/55" },
  { rotulo: "Imposto", valor: 0.76, tom: "bg-brand/35" },
  { rotulo: "Lucro", valor: 0.93, tom: "bg-brand/20" },
];

const UNITARIO = COMPOSICAO.reduce((total, item) => total + item.valor, 0);

export function CartaoDePreco() {
  return (
    <div className="rounded-xl border border-border bg-card p-5 shadow-sm">
      <p className="text-xs text-muted-foreground">
        Caixa rígida com tampa · 200 × 150 × 80 mm ·{" "}
        <span className="tabular-nums">{QUANTIDADE}</span> peças
      </p>

      <div className="mt-4 flex items-end justify-between gap-4">
        <div>
          <p className="text-[0.7rem] uppercase tracking-wide text-muted-foreground">
            Preço por peça
          </p>
          <p className="font-mono text-3xl font-semibold tabular-nums">
            {formatCurrency(UNITARIO)}
          </p>
        </div>

        <div className="text-right">
          <p className="text-[0.7rem] uppercase tracking-wide text-muted-foreground">Total</p>
          <p className="font-mono text-lg tabular-nums">
            {formatCurrency(UNITARIO * QUANTIDADE)}
          </p>
        </div>
      </div>

      {/* A barra: onde o dinheiro do cliente vai parar, na proporção real. */}
      <div className="mt-5 flex h-2 overflow-hidden rounded-full">
        {COMPOSICAO.map((item) => (
          <div
            key={item.rotulo}
            className={item.tom}
            style={{ width: `${(item.valor / UNITARIO) * 100}%` }}
          />
        ))}
      </div>

      <dl className="mt-4 space-y-2">
        {COMPOSICAO.map((item) => (
          <div key={item.rotulo} className="flex items-center gap-2.5 text-sm">
            <span className={`size-2 shrink-0 rounded-sm ${item.tom}`} aria-hidden />
            <dt className="flex-1 text-muted-foreground">{item.rotulo}</dt>
            <dd className="font-mono tabular-nums">{formatCurrency(item.valor)}</dd>
            <dd className="w-12 text-right font-mono text-xs tabular-nums text-muted-foreground">
              {((item.valor / UNITARIO) * 100).toFixed(0)}%
            </dd>
          </div>
        ))}
      </dl>
    </div>
  );
}

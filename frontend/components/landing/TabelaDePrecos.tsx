import Link from "next/link";
import { Check } from "lucide-react";

import { formatCurrency } from "@/lib/pricing/engine";
import { cn } from "@/lib/utils";
import type { Vitrine } from "@/lib/api";

import { ESTILO_CTA, ESTILO_CTA_SECUNDARIO } from "./estilos";

/**
 * A tabela de preços — desenhada a partir do que o servidor respondeu.
 *
 * Nenhuma mensalidade escrita aqui. A tela de assinatura já mostrou R$ 99,90 num
 * cartão enquanto o cabeçalho da mesma página dizia R$ 149,90, porque mantinha a
 * própria lista de planos; numa página pública o mesmo erro é pior, porque a
 * pessoa decide com base nele e só descobre a diferença na fatura.
 *
 * Se a API não responder, a seção some inteira em vez de mostrar preço velho —
 * ver `buscarVitrine`. Melhor não informar do que informar errado.
 */
export function TabelaDePrecos({ vitrine }: { vitrine: Vitrine | null }) {
  if (!vitrine) {
    return (
      <div className="rounded-xl border border-dashed border-border p-8 text-center">
        <p className="text-sm text-muted-foreground">
          A tabela de preços está indisponível no momento.
        </p>
        <Link
          href="/cadastro"
          className={cn(ESTILO_CTA, "mt-4 h-11 px-6 text-[0.95rem]")}
        >
          Criar conta e ver os planos
        </Link>
      </div>
    );
  }

  return (
    <div className="grid gap-5 md:grid-cols-3">
      {vitrine.planos.map((plano) => {
        const destacado = plano.tipo === "pro";

        return (
          <div
            key={plano.tipo}
            className={cn(
              "flex flex-col rounded-xl border p-6",
              /*
                O plano recomendado se separa por COR, não só por peso.

                Borda preta contra bordas cinzas é diferença de intensidade, e o
                olho lê isso como "mais forte" — não como "este". Em azul ele
                passa a ser reconhecido pela marca, na única seção da página em
                que a decisão de fato acontece.
              */
              destacado
                ? "border-brand bg-card shadow-md ring-1 ring-brand/20"
                : "border-border bg-card",
            )}
          >
            <div className="flex items-center justify-between gap-2">
              <h3 className="font-medium">{plano.rotulo}</h3>
              {/*
                O selo acompanha a borda: preenchido com a marca e texto branco
                (5,33:1), e não `--brand-on-inverted` — aqui o fundo é o AZUL,
                não a superfície invertida.
              */}
              {destacado && (
                <span className="rounded-full bg-brand px-2.5 py-0.5 text-[0.7rem] font-medium text-white">
                  Sem limites
                </span>
              )}
            </div>

            <p className="mt-4 flex items-baseline gap-1.5">
              <span className="font-mono text-3xl font-semibold tabular-nums">
                {plano.pago ? formatCurrency(plano.mensalidade) : "Grátis"}
              </span>
              {plano.pago && <span className="text-sm text-muted-foreground">/mês</span>}
            </p>

            <ul className="mt-6 flex-1 space-y-2.5 text-sm">
              <ItemDeCota
                rotulo="orçamentos por mês"
                semTeto="Ilimitados"
                valor={plano.limites.orcamentos_por_mes}
              />
              <ItemDeCota
                rotulo="matérias-primas"
                semTeto="Ilimitadas"
                valor={plano.limites.materiais}
              />
              <ItemDeCota rotulo="clientes" semTeto="Ilimitados" valor={plano.limites.clientes} />

              {/*
                A frase mais importante da tabela, e ela é verificável: no
                servidor, o plano decide APENAS cota. Nenhum recurso é destravado
                por preço — ver PlanType, onde só existem os três tetos acima.
              */}
              <li className="flex items-start gap-2 text-muted-foreground">
                <Check className="mt-0.5 size-4 shrink-0 text-brand" aria-hidden />
                <span>Todos os recursos, inclusive no gratuito</span>
              </li>
              <li className="flex items-start gap-2 text-muted-foreground">
                <Check className="mt-0.5 size-4 shrink-0 text-brand" aria-hidden />
                <span>Usuários da equipe sem cobrança por pessoa</span>
              </li>
            </ul>

            <Link
              href="/cadastro"
              className={cn(
                destacado ? ESTILO_CTA : ESTILO_CTA_SECUNDARIO,
                "mt-6 h-11 w-full px-5 text-[0.95rem]",
              )}
            >
              {plano.pago ? `Testar ${vitrine.dias_de_teste} dias` : "Começar grátis"}
            </Link>
          </div>
        );
      })}
    </div>
  );
}

/**
 * Uma linha de cota.
 *
 * `null` significa ilimitado — o mesmo contrato do resumo de cotas da API. Sem
 * este tratamento a tela imprimiria "null orçamentos por mês" justamente no
 * plano mais caro, que é onde a ausência de teto é o argumento de venda.
 *
 * `semTeto` vem escrito por extenso, e não montado a partir de "Ilimitado" mais
 * uma letra, porque em português o adjetivo concorda com o substantivo:
 * matérias-primas são ilimitadAs, clientes são ilimitadOs. Um único rótulo para
 * as três linhas erra em uma delas — e erro de concordância na tabela de preços
 * é a primeira coisa que o visitante lê como desleixo.
 */
function ItemDeCota({
  rotulo,
  valor,
  semTeto,
}: {
  rotulo: string;
  valor: number | null;
  semTeto: string;
}) {
  return (
    <li className="flex items-start gap-2">
      <Check className="mt-0.5 size-4 shrink-0 text-brand" aria-hidden />
      <span>
        <strong className="font-medium tabular-nums">
          {valor === null ? semTeto : valor.toLocaleString("pt-BR")}
        </strong>{" "}
        <span className="text-muted-foreground">{rotulo}</span>
      </span>
    </li>
  );
}

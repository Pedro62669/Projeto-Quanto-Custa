import Link from "next/link";
import { Check } from "lucide-react";

import { formatCurrency } from "@/lib/rotulos";
import { cn } from "@/lib/utils";
import type { Vitrine } from "@/lib/api";

import { ESTILO_CTA, ESTILO_CTA_SECUNDARIO } from "./estilos";
import { Revelar } from "./Revelar";

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
      <div className="rounded-2xl border border-dashed border-border bg-card/60 p-8 text-center">
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

  /*
    Duas colunas, e não três. A grade nasceu para três planos porque o gratuito
    ocupava a primeira; com ele fora, `md:grid-cols-3` deixaria dois cartões
    esticados ocupando dois terços e um vazio no fim.

    `max-w-3xl mx-auto` segura a largura: dois cartões espalhados por 72rem
    ficam com mais respiro interno do que texto, e a tabela perde a leitura de
    comparação lado a lado que é a função dela.
  */
  return (
    <div className="mx-auto grid max-w-3xl items-stretch gap-5 sm:grid-cols-2">
      {vitrine.planos.map((plano, indice) => {
        const destacado = plano.tipo === "pro";
        const temRiscado = typeof plano.mensalidade_cheia === "number";

        return (
          // A entrada (Revelar) fica num invólucro, e o cartão dentro dele: as
          // duas coisas mexem em `transition`, e no mesmo elemento a entrada
          // venceria — o cartão subiria ao passar o mouse sem animar.
          <Revelar key={plano.tipo} atraso={indice * 110} className="flex">
            <div
              className={cn(
                "flex w-full flex-col rounded-2xl border p-6 transition-[translate,box-shadow] duration-300 hover:-translate-y-1",
                /*
                  O plano recomendado se separa por COR, não só por peso.

                  Borda preta contra bordas cinzas é diferença de intensidade, e o
                  olho lê isso como "mais forte" — não como "este". Em azul ele
                  passa a ser reconhecido pela marca, na única seção da página em
                  que a decisão de fato acontece.
                */
                destacado
                  ? "border-brand bg-[linear-gradient(180deg,color-mix(in_oklch,var(--brand)_8%,var(--card)),var(--card)_42%)] shadow-marca ring-1 ring-brand/20"
                  : "border-border bg-card shadow-card hover:shadow-elevado",
              )}
            >
              <div className="flex items-center justify-between gap-2">
                <h3 className="font-heading text-lg font-semibold">{plano.rotulo}</h3>
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

              {/*
                O preço de lançamento, quando existe.

                O riscado sai do SERVIDOR (`mensalidade_cheia`), nunca escrito
                aqui. No dia em que a mensalidade subir, apaga-se o valor no
                `PlanType` e a tabela para de riscar sozinha — ninguém precisa
                lembrar de vir mexer nesta linha.

                A etiqueta "preço de lançamento" NÃO é decoração: é o que torna a
                oferta legítima. O CDC (art. 37, §1º) e o Decreto 5.903/2006
                exigem que o valor anunciado como anterior tenha sido praticado
                ou, num lançamento, que a subida seja real e informada. Riscar um
                número sem dizer o que ele é vira "de/por" fantasma, que é o caso
                que o Procon autua.

                `line-through` com `text-muted-foreground`: o riscado é referência,
                não a informação principal. Quem manda no olho é o preço que se
                paga.

                O teste é `typeof === "number"`, e não `!== null`. A resposta desta
                rota fica em cache por uma HORA (ver `buscarVitrine`), de propósito
                — é o que permite mudar o preço no enum PHP sem novo build. O
                efeito colateral é uma janela de até 60 minutos em que a tela é mais
                nova que o dado guardado, e o campo chega `undefined` em vez de
                `null`. `!== null` passa por undefined e chama `formatCurrency`
                com ele: a home inteira caiu com 500 assim, uma vez.
              */}
              {typeof plano.mensalidade_cheia === "number" && (
                <p className="mt-4 flex items-baseline gap-2">
                  <span className="font-mono text-lg tabular-nums text-muted-foreground line-through">
                    {formatCurrency(plano.mensalidade_cheia)}
                  </span>
                  <span className="rounded-full bg-brand/10 px-2 py-0.5 text-[0.7rem] font-medium text-brand-strong">
                    preço de lançamento
                  </span>
                </p>
              )}

              <p className={cn("flex items-baseline gap-1.5", temRiscado ? "mt-1.5" : "mt-4")}>
                <span className="font-mono text-[2rem] font-semibold tracking-tight tabular-nums">
                  {formatCurrency(plano.mensalidade)}
                </span>
                <span className="text-sm text-muted-foreground">/mês</span>
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
                  <span>Todos os recursos nos dois planos</span>
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
                Assinar o {plano.rotulo}
              </Link>
            </div>
          </Revelar>
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

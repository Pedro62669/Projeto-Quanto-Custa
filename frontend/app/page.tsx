import type { CSSProperties } from "react";
import type { Metadata } from "next";
import Link from "next/link";
import {
  ArrowRight,
  Boxes,
  Box,
  Check,
  Clock,
  FileText,
  Grid2x2,
  Package,
  Scissors,
  ShieldCheck,
  TrendingUp,
  Users,
} from "lucide-react";

import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import { CabecalhoPublico } from "@/components/landing/CabecalhoPublico";
import { CaixaAnimada } from "@/components/landing/CaixaAnimada";
import { CartaoDePreco } from "@/components/landing/CartaoDePreco";
import { FaixaDeModelos, TOTAL_DE_MODELOS } from "@/components/landing/FaixaDeModelos";
import { PlanoDeCorteIlustrado } from "@/components/landing/PlanoDeCorteIlustrado";
import { Revelar } from "@/components/landing/Revelar";
import { SistemaPorDentro } from "@/components/landing/SistemaPorDentro";
import { TabelaDePrecos } from "@/components/landing/TabelaDePrecos";
import {
  ESTILO_CTA,
  ESTILO_CTA_INVERTIDO,
  ESTILO_CTA_SECUNDARIO,
  ESTILO_SOBRETITULO,
} from "@/components/landing/estilos";
import { buscarVitrine } from "@/lib/api";
import { cn } from "@/lib/utils";

/** Atraso de entrada como variável de CSS — ver `.entra` em globals.css. */
const atraso = (ms: number) => ({ "--atraso": `${ms}ms` }) as CSSProperties;

/**
 * A página de vendas.
 *
 * Renderizada no SERVIDOR, e essa é a decisão que sustenta o resto: o texto e os
 * preços chegam prontos no HTML, então o buscador os indexa e o visitante lê
 * antes de qualquer JavaScript baixar. A única parte interativa é o cabeçalho,
 * que precisa saber se quem chegou já tem sessão.
 *
 * Nenhum número aqui é invenção de marketing. As mensalidades vêm de
 * `PlanType`, o prazo de arrependimento de `dias_de_arrependimento`, e cada
 * recurso listado corresponde a uma tela que existe. Uma landing que promete o
 * que o produto não faz não perde o cliente na assinatura — perde no primeiro
 * dia de uso, que é mais caro.
 *
 * Não há nada grátis a anunciar, e a página não anuncia. Sumiram o "começar
 * grátis", o teste de alguns dias e a coluna do plano gratuito: os dois planos
 * são pagos desde a primeira hora. O que a conta nova ganha é uma DEMONSTRAÇÃO
 * — entra, navega e vê a calculadora com um exemplo pronto, sem gravar nada.
 * Prometer isso como "grátis" seria repetir o problema que esta rodada
 * desmontou.
 */
export const metadata: Metadata = {
  title: "quantoCusta — Precificação e orçamento para cartonagem",
  description:
    "Calcule o preço de caixas rígidas com o seu papelão, o seu custo de hora e a perda real do corte. Proposta em PDF, plano de corte e financeiro no mesmo lugar.",
  openGraph: {
    title: "quantoCusta — Precificação para cartonagem",
    description:
      "Do desenho da caixa ao preço fechado, com a perda real da chapa e o custo da sua hora de trabalho.",
    type: "website",
    locale: "pt_BR",
  },
};

export default async function VitrinePage() {
  const vitrine = await buscarVitrine();

  return (
    <>
      {/*
        Sem JavaScript, nada fica escondido.

        As entradas ao rolar começam com opacidade zero e dependem de um
        observador para aparecer (ver <Revelar />). Se o script não rodar —
        bloqueador, rede que caiu no meio, navegador antigo —, esta regra
        devolve a página inteira. Texto invisível à espera de um script que não
        vem é o pior modo de uma página de vendas falhar.
      */}
      <noscript>
        <style>{`[data-revelar]{opacity:1!important;transform:none!important}`}</style>
      </noscript>

      <CabecalhoPublico />

      <main className="flex-1">
        <Heroi />
        <FaixaDeModelos />
        <ContasQueAPlanilhaErra />
        {/* A prova vem logo depois da queixa, na mesma ordem: problema, tela que
            o resolve. Sem teste grátis e sem cliente para depor, é o produto se
            mostrando que faz o trabalho de convencer. */}
        <SistemaPorDentro />
        <Recursos />
        <ComoFunciona />
        <Precos vitrine={vitrine} />
        <Perguntas vitrine={vitrine} />
        <Fechamento />
      </main>

      <Rodape />
    </>
  );
}

/* ── Herói ──────────────────────────────────────────────────────────────── */

/**
 * A primeira tela.
 *
 * Tudo aqui entra com animação de CSS (`.entra`), em fila: selo, título, texto,
 * botões — e do outro lado a chapa, a caixa e o preço, na ordem em que o
 * produto trabalha. Nada depende de JavaScript para aparecer: o herói é o que
 * está na tela enquanto o script ainda baixa.
 *
 * `-mt-16 pt-16` puxa a seção para baixo do cabeçalho, que no topo da página é
 * transparente: o fundo do herói começa na borda da janela, e não numa faixa
 * branca de 64px acima dele.
 */
function Heroi() {
  return (
    <section className="relative -mt-16 overflow-hidden pt-16">
      {/* Fundo: malha de desenho técnico e duas manchas de cor que derivam
          devagar — o azul da marca e o kraft do papelão. Decoração pura, fora
          da árvore de acessibilidade. */}
      <div aria-hidden className="pointer-events-none absolute inset-0">
        <div className="malha absolute inset-0" />
        <div className="deriva absolute -top-32 right-[-8%] size-[34rem] rounded-full bg-brand/14 blur-3xl" />
        <div className="deriva-lenta absolute bottom-[-18%] left-[-10%] size-[30rem] rounded-full bg-kraft/22 blur-3xl" />
      </div>

      <div className="relative mx-auto grid max-w-6xl items-center gap-12 px-5 py-12 lg:grid-cols-[1.12fr_0.88fr] lg:py-14">
        <div>
          <p
            className="entra inline-flex items-center gap-2 rounded-full border border-border bg-card/80 py-1 pl-2.5 pr-3 text-xs text-muted-foreground shadow-xs backdrop-blur"
            style={atraso(0)}
          >
            <span className="pulso size-1.5 rounded-full bg-brand" aria-hidden />
            Feito para cartonagem rígida
          </p>

          {/*
            A pergunta em tinta, a resposta em azul.

            Preto é o que o visitante já pensa, azul é o que o produto responde.
            Texto grande, então 5,33:1 sobra com folga. O risco sob "sua hora"
            é kraft — a cor do papelão sublinhando a frase que fala do custo
            dele — e se desenha depois que o título pousa, como caneta.
          */}
          <h1
            className="entra mt-6 text-[2.6rem] leading-[1.02] font-bold tracking-tight sm:text-[3.5rem]"
            style={atraso(90)}
          >
            Quanto custa essa caixa?
            <span className="mt-1 block text-brand">
              Com o seu papelão e a{" "}
              <span className="relative whitespace-nowrap">
                sua hora.
                <svg
                  aria-hidden
                  viewBox="0 0 200 14"
                  preserveAspectRatio="none"
                  className="absolute -bottom-2 left-0 h-3 w-full text-kraft"
                >
                  <path
                    className="risco"
                    style={atraso(950)}
                    pathLength={1}
                    d="M3 9 C 48 2, 112 13, 197 5"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="4"
                    strokeLinecap="round"
                  />
                </svg>
              </span>
            </span>
          </h1>

          <p
            className="entra mt-7 max-w-xl text-base leading-relaxed text-muted-foreground sm:text-lg"
            style={atraso(180)}
          >
            Você digita as medidas e a quantidade. O sistema encaixa as peças na
            chapa, mede a perda que o corte realmente gera, soma o custo do
            minuto da sua empresa e devolve o preço — com a proposta em PDF e o
            plano de corte para a bancada.
          </p>

          <div className="entra mt-8 flex flex-wrap items-center gap-3" style={atraso(270)}>
            {/*
              O botão nomeia o que ACONTECE ao clicar, e não o que o visitante
              tem de fazer. "Criar minha conta" descreve trabalho; "ver o sistema
              calculando" descreve o que ele ganha — e é literalmente a próxima
              tela, então a promessa se cumpre em segundos.
            */}
            <Link href="/cadastro" className={cn(ESTILO_CTA, "group h-12 px-6 text-base")}>
              Ver o sistema calculando
              <ArrowRight
                className="size-4 text-brand-on-inverted transition-[translate] duration-200 group-hover:translate-x-1"
                aria-hidden
              />
            </Link>
            <a href="#como-funciona" className={cn(ESTILO_CTA_SECUNDARIO, "h-12 px-6 text-base")}>
              Ver como funciona
            </a>
          </div>

          {/*
            A linha de risco, logo abaixo do botão.

            Aqui morava a promessa do teste grátis, e depois de removê-la sobrou
            uma constatação seca — "para orçar as suas caixas, escolha um plano"
            — que se lê como "pague primeiro". A mesma verdade, dita na ordem em
            que o visitante decide, é o argumento mais forte da página: ele ENTRA
            e VÊ antes de pagar, e quando paga tem sete dias para desistir com o
            dinheiro de volta.

            Nada aqui é promessa nova: a conta sem plano abre com a calculadora
            em modo demonstração (ver EnsureSubscriptionIsActive e /quotes/demo),
            e o estorno integral é o que o SubscriptionManager executa.
          */}
          <p className="entra mt-4 text-sm text-muted-foreground" style={atraso(340)}>
            Sem cartão para criar a conta. Você entra, navega e vê a calculadora
            precificando uma caixa de verdade — com os seus custos. Se assinar e
            não gostar,{" "}
            <span className="font-medium text-foreground">
              tem 7 dias para pedir o dinheiro de volta
            </span>
            .
          </p>

          {/*
            Três fatos, e os três verificáveis.

            O número de modelos é CONTADO da lista que o sistema usa (ver
            FaixaDeModelos), não escrito: entra um modelo novo, o herói
            atualiza sozinho. Os outros dois são telas que existem.
          */}
          <ul
            className="entra mt-9 flex flex-wrap gap-x-7 gap-y-3 border-t border-border/70 pt-6 text-sm"
            style={atraso(430)}
          >
            {[
              `${TOTAL_DE_MODELOS} modelos de caixa prontos`,
              "Perda medida no corte",
              "Proposta em PDF com a sua marca",
            ].map((fato) => (
              <li key={fato} className="flex items-center gap-2 text-muted-foreground">
                <Check className="size-4 text-brand" aria-hidden />
                {fato}
              </li>
            ))}
          </ul>
        </div>

        {/*
          O produto, em três tempos: a chapa, a caixa, o preço.

          É a ordem em que o sistema trabalha, e a ordem em que as três peças
          entram. As duas de cima e de baixo reproduzem telas reais; a caixa é
          a ilustração que as liga.
        */}
        <div className="relative">
          <div className="entra-escala" style={atraso(200)}>
            <PlanoDeCorteIlustrado />
          </div>

          <div className="mt-4 grid items-stretch gap-4 sm:grid-cols-[minmax(0,0.5fr)_minmax(0,1fr)]">
            <div
              className="entra-escala hidden overflow-hidden rounded-2xl border border-border bg-card/85 shadow-elevado backdrop-blur sm:grid sm:place-items-center"
              style={atraso(420)}
            >
              <CaixaAnimada />
            </div>

            <div className="entra-escala" style={atraso(560)}>
              <CartaoDePreco />
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

/* ── O problema ─────────────────────────────────────────────────────────── */

/**
 * As três contas que a planilha erra.
 *
 * A seção existe porque o visitante desta página já tem uma planilha e acha que
 * ela resolve. O argumento não é "planilha é ruim" — é apontar exatamente onde
 * ela silencia, e cada um dos três casos corresponde a um módulo do sistema.
 *
 * É a única seção de fundo ESCURO no meio da página, e por isso: é a virada do
 * argumento. Até aqui a página apresentou; daqui em diante ela prova. A troca
 * de fundo marca essa passagem melhor do que qualquer título.
 */
function ContasQueAPlanilhaErra() {
  const contas = [
    {
      titulo: "A perda que você cotou não é a perda que aconteceu",
      texto:
        "A planilha aplica um percentual fixo de quebra. O corte não: quatro peças de 245 mm numa chapa de 1000 sobram 20 mm de tira inútil. O sistema encaixa as peças de verdade e mostra a diferença entre o que foi cotado e o que foi gasto.",
    },
    {
      titulo: "A sua hora não custa o salário dividido por 220",
      texto:
        "Aluguel, energia, contador, depreciação da guilhotina e o tempo que a máquina fica parada entram no preço de cada peça. O sistema calcula o custo do minuto da empresa em três cenários de eficiência, porque ninguém produz 100% do horário.",
    },
    {
      titulo: "Preço fechado não é custo mais margem",
      texto:
        "Imposto incide sobre a venda, não sobre o custo — aplicar a margem antes e o imposto depois entrega um lucro menor do que o combinado. Aqui a conta é feita na ordem certa, e a composição do preço fica aberta para você conferir.",
    },
  ];

  return (
    <section className="relative overflow-hidden bg-primary text-primary-foreground">
      <div aria-hidden className="pointer-events-none absolute inset-0">
        <div className="malha-clara absolute inset-0" />
        <div className="absolute -top-40 left-1/2 size-[36rem] -translate-x-1/2 rounded-full bg-brand/25 blur-3xl" />
      </div>

      <div className="relative mx-auto max-w-6xl px-5 py-20 lg:py-24">
        <Revelar>
          <p className={cn(ESTILO_SOBRETITULO, "text-brand-on-inverted")}>O problema</p>
          <h2 className="mt-3 max-w-2xl text-3xl font-semibold tracking-tight sm:text-4xl">
            Três contas que a planilha erra sem avisar
          </h2>
        </Revelar>

        <div className="mt-12 grid gap-5 md:grid-cols-3">
          {contas.map((conta, indice) => (
            <Revelar key={conta.titulo} atraso={indice * 120} className="flex">
              <div className="flex w-full flex-col rounded-2xl border border-white/10 bg-white/[0.04] p-6 backdrop-blur-sm transition-[translate,border-color,background-color] duration-300 hover:-translate-y-1 hover:border-white/25 hover:bg-white/[0.07]">
                <span className="font-heading text-5xl font-bold text-brand-on-inverted tabular-nums">
                  {String(indice + 1).padStart(2, "0")}
                </span>
                <h3 className="mt-5 text-lg leading-snug font-medium">{conta.titulo}</h3>
                <p className="mt-3 text-sm leading-relaxed text-primary-foreground/65">
                  {conta.texto}
                </p>
              </div>
            </Revelar>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ── Recursos ───────────────────────────────────────────────────────────── */

function Recursos() {
  const recursos = [
    {
      icone: Box,
      titulo: "Caixa em 3D, na hora",
      texto:
        "Altere uma medida e veja a caixa mudar. Modelos prontos de cartonagem rígida e o modelo livre, para a peça que não cabe em fórmula nenhuma.",
    },
    {
      icone: Grid2x2,
      titulo: "Plano de corte com a perda real",
      texto:
        "As peças são encaixadas na chapa respeitando corte de guilhotina, espessura da lâmina e sentido de fibra. Você vê o arranjo, a sobra e quanto ela custou.",
    },
    {
      icone: Clock,
      titulo: "Hora-empresa",
      texto:
        "Custos fixos, equipamentos e depreciação viram um custo por minuto. Publicar uma versão nova não mexe em orçamento já fechado.",
    },
    {
      icone: FileText,
      titulo: "Proposta e ficha técnica",
      texto:
        "PDF com o seu logotipo e CNPJ para o cliente; ficha técnica com gabarito e lista de separação para a bancada, feita para ser impressa.",
    },
    {
      icone: TrendingUp,
      titulo: "Do orçamento ao caixa",
      texto:
        "Orçamento aprovado gera as parcelas. O painel financeiro mostra realizado, projetado, vencidos e ponto de equilíbrio do mês.",
    },
    {
      icone: Boxes,
      titulo: "Seus materiais, seus custos",
      texto:
        "Cadastre o papelão que você compra, por m², por quilo ou por lote com frete. O sistema resolve a conversão e mostra o custo por m² que usou.",
    },
    {
      icone: Users,
      titulo: "Equipe sem custo por usuário",
      texto:
        "Convide quem trabalha com você. Administradores cuidam de custos e cadastros; a equipe orça e vende.",
    },
    {
      icone: Scissors,
      titulo: "Orçamento que não muda sozinho",
      texto:
        "Cada orçamento guarda os custos vigentes no dia em que foi feito. Reajustar o papelão amanhã não reescreve o preço que você já mandou.",
    },
  ];

  return (
    <section id="recursos" className="scroll-mt-20">
      <div className="mx-auto max-w-6xl px-5 py-20 lg:py-24">
        <Revelar>
          <p className={ESTILO_SOBRETITULO}>Recursos</p>
          <h2 className="mt-3 max-w-2xl text-3xl font-semibold tracking-tight sm:text-4xl">
            Tudo que a cartonagem precisa entre o pedido e o caixa
          </h2>
        </Revelar>

        {/*
          Grade em mosaico: os dois primeiros e os dois últimos ocupam duas
          colunas. Não é sorteio — a primeira fileira são os dois recursos que
          ninguém mais tem (o 3D e o plano de corte), e é por eles que a pessoa
          chegou até aqui.
        */}
        <div className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {recursos.map((recurso, indice) => {
            const largo = indice < 2 || indice >= recursos.length - 2;

            return (
              <Revelar
                key={recurso.titulo}
                atraso={(indice % 4) * 80}
                className={cn("flex", largo && "lg:col-span-2")}
              >
                <div className="group flex w-full flex-col rounded-2xl border border-border bg-card p-6 shadow-card transition-[translate,box-shadow,border-color] duration-300 hover:-translate-y-1 hover:border-brand/35 hover:shadow-elevado">
                  {/* O ícone inverte ao passar o mouse: de azul sobre azul
                      claro para branco sobre a marca. É o único elemento do
                      cartão que muda de cor, e basta. */}
                  <span className="flex size-10 items-center justify-center rounded-xl bg-brand/10 text-brand transition-colors duration-300 group-hover:bg-brand group-hover:text-white">
                    <recurso.icone className="size-5" aria-hidden />
                  </span>
                  <h3 className="mt-5 text-[1.05rem] leading-snug font-medium">
                    {recurso.titulo}
                  </h3>
                  <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                    {recurso.texto}
                  </p>
                </div>
              </Revelar>
            );
          })}
        </div>
      </div>
    </section>
  );
}

/* ── Como funciona ──────────────────────────────────────────────────────── */

function ComoFunciona() {
  const passos = [
    {
      titulo: "Cadastre o que você compra",
      texto:
        "Papelão, papel de revestimento, cola, ferragem. Por m², por quilo ou pelo lote com frete rateado. A conta de conversão é do sistema.",
    },
    {
      titulo: "Diga o custo da sua estrutura",
      texto:
        "Aluguel, energia, salários, máquinas. Uma vez. É o que transforma tempo de produção em dinheiro no preço da peça.",
    },
    {
      titulo: "Orce em segundos, quantas vezes quiser",
      texto:
        "Medidas, quantidade, material e margem. O preço aparece enquanto você digita, com a composição aberta e o plano de corte pronto para imprimir.",
    },
  ];

  return (
    <section
      id="como-funciona"
      className="scroll-mt-20 border-y border-border/60 bg-[linear-gradient(180deg,var(--muted),var(--background))]"
    >
      <div className="mx-auto max-w-6xl px-5 py-20 lg:py-24">
        <Revelar>
          <p className={ESTILO_SOBRETITULO}>Como funciona</p>
          <h2 className="mt-3 max-w-2xl text-3xl font-semibold tracking-tight sm:text-4xl">
            Duas configurações e você orça para sempre
          </h2>
          <p className="mt-4 max-w-2xl text-muted-foreground">
            A conta abre com matérias-primas e custos de exemplo já cadastrados —
            para você ver o sistema funcionando antes de digitar qualquer coisa.
          </p>
        </Revelar>

        <ol className="relative mt-14 grid gap-6 md:grid-cols-3">
          {/* O fio que liga os três passos, por trás dos números. Só no
              desktop: empilhados no celular, a ordem já é a da leitura. */}
          <span
            aria-hidden
            className="absolute top-5 right-[16.6%] left-[16.6%] hidden h-px bg-[linear-gradient(90deg,transparent,var(--brand),transparent)] opacity-40 md:block"
          />

          {passos.map((passo, indice) => (
            <Revelar como="li" key={passo.titulo} atraso={indice * 140} className="relative">
              {/* Quadrado de tinta, número azul — a mesma regra da logo. */}
              <span className="relative mx-auto flex size-10 items-center justify-center rounded-xl bg-primary font-mono text-sm text-brand-on-inverted tabular-nums shadow-elevado md:mx-0">
                {indice + 1}
              </span>
              <div className="mt-6 rounded-2xl border border-border bg-card p-6 shadow-card">
                <h3 className="text-[1.05rem] font-medium">{passo.titulo}</h3>
                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                  {passo.texto}
                </p>
              </div>
            </Revelar>
          ))}
        </ol>
      </div>
    </section>
  );
}

/* ── Preços ─────────────────────────────────────────────────────────────── */

function Precos({ vitrine }: { vitrine: Awaited<ReturnType<typeof buscarVitrine>> }) {
  return (
    <section id="precos" className="scroll-mt-20">
      <div className="mx-auto max-w-6xl px-5 py-20 lg:py-24">
        <Revelar className="text-center">
          <p className={ESTILO_SOBRETITULO}>Preços</p>
          <h2 className="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">Planos</h2>
          <p className="mx-auto mt-4 max-w-2xl text-muted-foreground">
            O plano decide o volume, nunca o que você pode fazer. Todos os recursos
            estão nos dois planos.
          </p>
        </Revelar>

        <div className="mt-12">
          <TabelaDePrecos vitrine={vitrine} />
        </div>

        <GarantiaDeArrependimento vitrine={vitrine} />
      </div>
    </section>
  );
}

/**
 * A garantia, colada no preço.
 *
 * É a peça que ocupa o lugar que o teste grátis deixou vago — e é melhor que
 * ele em dois aspectos: vale para o produto INTEIRO (não uma versão limitada por
 * alguns dias) e é uma obrigação legal que o sistema cumpre sozinho, não uma
 * cortesia que alguém pode esquecer de honrar.
 *
 * Fica AQUI, encostada na tabela, porque é aqui que a objeção acontece: o
 * visitante acabou de ler R$ 149,90 e a pergunta seguinte é "e se não servir
 * para mim?". A resposta estava a dois cliques de distância, dentro de um
 * acordeão fechado no fim da página.
 *
 * O prazo vem do servidor (`dias_de_arrependimento`), como as mensalidades. É o
 * mesmo número que o `SubscriptionManager` usa para decidir o estorno — escrever
 * "7" à mão aqui criaria uma promessa que ninguém garante.
 */
function GarantiaDeArrependimento({
  vitrine,
}: {
  vitrine: Awaited<ReturnType<typeof buscarVitrine>>;
}) {
  const dias = vitrine?.dias_de_arrependimento ?? null;

  return (
    <div className="mx-auto mt-8 flex max-w-3xl items-start gap-3.5 rounded-xl border border-brand/30 bg-brand/5 p-5">
      <ShieldCheck className="mt-0.5 size-5 shrink-0 text-brand" aria-hidden />

      <div>
        <p className="font-medium">
          {dias === null
            ? "Garantia de devolução integral"
            : `${dias} dias de garantia, dinheiro de volta`}
        </p>
        <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">
          {dias === null
            ? "Você tem o direito de arrependimento previsto no Código de Defesa do Consumidor, com devolução integral do valor pago."
            : `Cancelou dentro de ${dias} dias corridos, o valor volta inteiro — é o art. 49 do Código de Defesa do Consumidor, e o cancelamento é um botão na tela de assinatura. Enquanto o prazo corre, o sistema mostra até que dia o seu direito vale.`}
        </p>
      </div>
    </div>
  );
}

/* ── Perguntas ──────────────────────────────────────────────────────────── */

function Perguntas({ vitrine }: { vitrine: Awaited<ReturnType<typeof buscarVitrine>> }) {
  const perguntas = [
    {
      /*
       * A objeção que o visitante faz em silêncio, respondida primeiro.
       *
       * Um produto pago que não oferece teste levanta a pergunta querendo ou
       * não. Deixá-la sem resposta faz o visitante inventar a dele — quase
       * sempre "devem estar escondendo alguma coisa". Respondida de frente, e
       * com um motivo que só é possível dizer quando é verdade, ela deixa de ser
       * objeção e vira argumento.
       */
      pergunta: "Por que não tem teste grátis?",
      resposta:
        "Porque teste grátis obriga a inventar uma versão pela metade — e o que trava numa versão pela metade é exatamente o que você precisa avaliar. Aqui a conta abre sem cartão e mostra o sistema inteiro funcionando, com a calculadora precificando uma caixa de verdade. Você só não grava nada até assinar. E, se assinar e não gostar, tem o prazo de arrependimento do CDC para receber tudo de volta — o que é mais do que um teste de alguns dias oferece.",
    },
    {
      pergunta: "Dá para ver o sistema antes de assinar?",
      resposta:
        "Dá. O cadastro abre a conta na hora, sem cartão, e você entra e navega por todas as telas. A calculadora vem com uma caixa de exemplo já precificada pelo mesmo motor que os assinantes usam — o preço que aparece ali é uma conta de verdade. O que a conta sem plano não faz é gravar: para orçar as suas caixas, salvar propostas e usar o financeiro, é preciso assinar.",
    },
    {
      pergunta: "E se eu assinar e me arrepender?",
      resposta:
        vitrine === null
          ? "Você tem o direito de arrependimento previsto no Código de Defesa do Consumidor, com devolução integral."
          : `O art. 49 do Código de Defesa do Consumidor dá ${vitrine.dias_de_arrependimento} dias corridos para desistir de compra feita pela internet, com devolução integral. O cancelamento dentro do prazo estorna o valor inteiro, e o sistema mostra até que dia esse direito vale.`,
    },
    {
      pergunta: "Serve para a minha caixa, que é fora do padrão?",
      resposta:
        "Serve. Além dos modelos prontos de cartonagem rígida, existe o modelo livre: você descreve cada peça com as próprias medidas e quantidade, e o cálculo, o 3D e o plano de corte funcionam igual.",
    },
    {
      pergunta: "Meu preço muda quando o papelão aumenta?",
      resposta:
        "Só dali para a frente. Cada orçamento salvo guarda os custos vigentes no dia em que foi feito, então reajustar o material hoje não reescreve a proposta que você mandou semana passada.",
    },
    {
      pergunta: "Consigo tirar meus dados se eu sair?",
      resposta:
        "Sim. Suas propostas e fichas técnicas continuam saindo em PDF mesmo com a assinatura vencida — o vencimento bloqueia cadastrar coisas novas, nunca ler o que já é seu. A exclusão definitiva da conta, prevista na LGPD, fica na tela de assinatura.",
    },
    {
      pergunta: "Quantas pessoas podem usar?",
      resposta:
        "Quantas você precisar, em qualquer plano. A cobrança é por empresa, não por usuário: quem vende orça, quem administra cuida dos custos.",
    },
  ];

  return (
    <section
      id="perguntas"
      className="scroll-mt-20 border-t border-border/60 bg-[linear-gradient(180deg,var(--muted),var(--background))]"
    >
      <div className="mx-auto grid max-w-6xl gap-10 px-5 py-20 lg:grid-cols-[0.8fr_1.2fr] lg:py-24">
        <Revelar>
          <p className={ESTILO_SOBRETITULO}>Dúvidas</p>
          <h2 className="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">Perguntas</h2>
          <p className="mt-4 max-w-sm text-muted-foreground">
            O que costuma ser perguntado antes de criar a conta — respondido com o
            que o sistema faz, não com o que soa bem.
          </p>
        </Revelar>

        <Revelar atraso={120}>
          <div className="rounded-2xl border border-border bg-card px-6 py-2 shadow-card">
            <Accordion type="single" collapsible>
              {perguntas.map((item) => (
                <AccordionItem key={item.pergunta} value={item.pergunta}>
                  <AccordionTrigger className="py-4 text-left text-[0.95rem]">
                    {item.pergunta}
                  </AccordionTrigger>
                  <AccordionContent className="text-sm leading-relaxed text-muted-foreground">
                    {item.resposta}
                  </AccordionContent>
                </AccordionItem>
              ))}
            </Accordion>
          </div>
        </Revelar>
      </div>
    </section>
  );
}

/* ── Fechamento ─────────────────────────────────────────────────────────── */

/**
 * A última chamada.
 *
 * Um painel de tinta dentro da página clara, e não mais uma faixa de largura
 * inteira: depois de sete seções empilhadas, o que faz o olho parar é a forma
 * mudar. As manchas azuis derivam por trás, como no herói — a página fecha com
 * o mesmo gesto com que abriu.
 */
function Fechamento() {
  return (
    <section className="px-5 pb-6">
      <Revelar className="mx-auto max-w-6xl">
        <div className="relative overflow-hidden rounded-3xl bg-primary px-6 py-16 text-center text-primary-foreground shadow-elevado sm:px-10 lg:py-20">
          <div aria-hidden className="pointer-events-none absolute inset-0">
            <div className="malha-clara absolute inset-0" />
            <div className="deriva absolute -top-24 -left-16 size-[26rem] rounded-full bg-brand/35 blur-3xl" />
            <div className="deriva-lenta absolute -right-20 -bottom-28 size-[28rem] rounded-full bg-brand-on-inverted/20 blur-3xl" />
          </div>

          <div className="relative">
            <h2 className="mx-auto max-w-2xl text-3xl font-semibold tracking-tight sm:text-[2.6rem] sm:leading-[1.08]">
              O próximo orçamento pode sair com o número certo
            </h2>
            {/*
              O fechamento diz o CAMINHO, e não uma exortação.

              Aqui morava a promessa do teste grátis, e depois de removê-la sobrou
              um "crie a conta e faça a primeira conta em poucos minutos" que não
              responde nenhuma das duas perguntas de quem chegou até o fim da
              página: o que acontece quando eu clicar, e o que eu arrisco.

              Os dois passos estão em ordem e são verificáveis: a conta abre sem
              cartão, e o pagamento só entra depois — com o prazo do CDC correndo.
            */}
            <p className="mx-auto mt-5 max-w-xl text-primary-foreground/70">
              Crie a conta sem cartão e veja a calculadora funcionando na primeira
              tela. Quando fizer sentido, assine — com{" "}
              <span className="font-medium text-primary-foreground">
                garantia de devolução integral
              </span>{" "}
              no prazo do Código de Defesa do Consumidor.
            </p>

            <div className="mt-9 flex flex-wrap justify-center gap-3">
              <Link
                href="/cadastro"
                className={cn(ESTILO_CTA_INVERTIDO, "group h-12 px-7 text-base")}
              >
                Criar conta e ver funcionando
                <ArrowRight
                  className="size-4 text-brand transition-[translate] duration-200 group-hover:translate-x-1"
                  aria-hidden
                />
              </Link>
              <Link
                href="/login"
                className="inline-flex h-12 items-center justify-center rounded-xl border border-white/20 px-7 text-base font-medium text-primary-foreground transition-colors hover:border-white/40 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-on-inverted"
              >
                Já tenho conta
              </Link>
            </div>
          </div>
        </div>
      </Revelar>
    </section>
  );
}

/* ── Rodapé ─────────────────────────────────────────────────────────────── */

function Rodape() {
  return (
    <footer className="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-x-8 gap-y-5 px-5 py-10 text-sm text-muted-foreground">
      <div className="flex items-center gap-3">
        <span className="flex size-7 items-center justify-center rounded-lg bg-primary">
          <Package className="size-3.5 text-brand-on-inverted" aria-hidden />
        </span>
        <p>
          <span className="font-heading font-semibold text-foreground">quantoCusta</span>
          <span className="mx-2 text-border">·</span>© {new Date().getFullYear()}
        </p>
      </div>

      <nav aria-label="Rodapé" className="flex flex-wrap items-center gap-5">
        <a href="#recursos" className="transition-colors hover:text-foreground">
          Recursos
        </a>
        <a href="#precos" className="transition-colors hover:text-foreground">
          Preços
        </a>
        <Link href="/login" className="transition-colors hover:text-foreground">
          Entrar
        </Link>
        <Link href="/cadastro" className="transition-colors hover:text-foreground">
          Criar conta
        </Link>
      </nav>
    </footer>
  );
}

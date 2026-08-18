import type { Metadata } from "next";
import Link from "next/link";
import {
  ArrowRight,
  Boxes,
  Box,
  Clock,
  FileText,
  Grid2x2,
  Scissors,
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
import { CartaoDePreco } from "@/components/landing/CartaoDePreco";
import { PlanoDeCorteIlustrado } from "@/components/landing/PlanoDeCorteIlustrado";
import { TabelaDePrecos } from "@/components/landing/TabelaDePrecos";
import { ESTILO_CTA, ESTILO_CTA_SECUNDARIO } from "@/components/landing/estilos";
import { buscarVitrine } from "@/lib/api";
import { cn } from "@/lib/utils";

/**
 * A página de vendas.
 *
 * Renderizada no SERVIDOR, e essa é a decisão que sustenta o resto: o texto e os
 * preços chegam prontos no HTML, então o buscador os indexa e o visitante lê
 * antes de qualquer JavaScript baixar. A única parte interativa é o cabeçalho,
 * que precisa saber se quem chegou já tem sessão.
 *
 * Nenhum número aqui é invenção de marketing. As mensalidades vêm de
 * `PlanType`, o prazo de teste de `billing.dias_de_teste`, o de arrependimento
 * de `dias_de_arrependimento`, e cada recurso listado corresponde a uma tela que
 * existe. Uma landing que promete o que o produto não faz não perde o cliente na
 * assinatura — perde no primeiro dia de uso, que é mais caro.
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
      <CabecalhoPublico />

      <main className="flex-1">
        <Heroi dias={vitrine?.dias_de_teste ?? null} />
        <ContasQueAPlanilhaErra />
        <Recursos />
        <ComoFunciona />
        <Precos vitrine={vitrine} />
        <Perguntas vitrine={vitrine} />
        <Fechamento dias={vitrine?.dias_de_teste ?? null} />
      </main>

      <Rodape />
    </>
  );
}

/* ── Herói ──────────────────────────────────────────────────────────────── */

function Heroi({ dias }: { dias: number | null }) {
  return (
    <section className="border-b border-border/60">
      <div className="mx-auto grid max-w-6xl items-center gap-12 px-5 py-16 lg:grid-cols-[1.05fr_1fr] lg:py-24">
        <div>
          <p className="inline-flex items-center gap-2 rounded-full border border-border px-3 py-1 text-xs text-muted-foreground">
            <Box className="size-3.5" aria-hidden />
            Feito para cartonagem rígida
          </p>

          {/*
            A pergunta em preto, a resposta em azul.

            A segunda linha já era a que se diferenciava — vinha em cinza. Trocar
            o cinza pela marca faz a mesma separação dizendo mais: preto é o que
            o visitante já pensa, azul é o que o produto responde. Texto grande,
            então 5,33:1 sobra com folga.
          */}
          <h1 className="mt-5 text-4xl font-semibold leading-[1.08] tracking-tight sm:text-5xl">
            Quanto custa essa caixa?
            <span className="block text-brand">Com o seu papelão e a sua hora.</span>
          </h1>

          <p className="mt-5 max-w-xl text-base leading-relaxed text-muted-foreground sm:text-lg">
            Você digita as medidas e a quantidade. O sistema encaixa as peças na
            chapa, mede a perda que o corte realmente gera, soma o custo do
            minuto da sua empresa e devolve o preço — com a proposta em PDF e o
            plano de corte para a bancada.
          </p>

          <div className="mt-8 flex flex-wrap items-center gap-3">
            <Link href="/cadastro" className={cn(ESTILO_CTA, "h-12 px-6 text-base")}>
              Começar grátis
              <ArrowRight className="size-4" aria-hidden />
            </Link>
            <a href="#como-funciona" className={cn(ESTILO_CTA_SECUNDARIO, "h-12 px-6 text-base")}>
              Ver como funciona
            </a>
          </div>

          <p className="mt-4 text-sm text-muted-foreground">
            {dias === null
              ? "Sem cartão de crédito. O plano gratuito não expira."
              : `${dias} dias com o plano completo, sem cartão de crédito. Depois a conta continua no gratuito — nada é bloqueado.`}
          </p>
        </div>

        <div className="space-y-4">
          <PlanoDeCorteIlustrado />
          <CartaoDePreco />
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
    <section className="border-b border-border/60 bg-muted/30">
      <div className="mx-auto max-w-6xl px-5 py-16 lg:py-20">
        <h2 className="max-w-2xl text-2xl font-semibold tracking-tight sm:text-3xl">
          Três contas que a planilha erra sem avisar
        </h2>

        <div className="mt-10 grid gap-8 md:grid-cols-3">
          {contas.map((conta, indice) => (
            <div key={conta.titulo}>
              <span className="font-mono text-sm text-muted-foreground tabular-nums">
                {String(indice + 1).padStart(2, "0")}
              </span>
              <h3 className="mt-2 font-medium leading-snug">{conta.titulo}</h3>
              <p className="mt-2.5 text-sm leading-relaxed text-muted-foreground">
                {conta.texto}
              </p>
            </div>
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
    <section id="recursos" className="scroll-mt-20 border-b border-border/60">
      <div className="mx-auto max-w-6xl px-5 py-16 lg:py-20">
        <h2 className="max-w-2xl text-2xl font-semibold tracking-tight sm:text-3xl">
          Tudo que a cartonagem precisa entre o pedido e o caixa
        </h2>

        <div className="mt-10 grid gap-x-10 gap-y-9 sm:grid-cols-2 lg:grid-cols-4">
          {recursos.map((recurso) => (
            <div key={recurso.titulo}>
              <recurso.icone className="size-5 text-foreground" aria-hidden />
              <h3 className="mt-3 font-medium leading-snug">{recurso.titulo}</h3>
              <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                {recurso.texto}
              </p>
            </div>
          ))}
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
    <section id="como-funciona" className="scroll-mt-20 border-b border-border/60 bg-muted/30">
      <div className="mx-auto max-w-6xl px-5 py-16 lg:py-20">
        <h2 className="max-w-2xl text-2xl font-semibold tracking-tight sm:text-3xl">
          Duas configurações e você orça para sempre
        </h2>
        <p className="mt-3 max-w-2xl text-muted-foreground">
          A conta abre com matérias-primas e custos de exemplo já cadastrados —
          para você ver o sistema funcionando antes de digitar qualquer coisa.
        </p>

        <ol className="mt-10 grid gap-6 md:grid-cols-3">
          {passos.map((passo, indice) => (
            <li key={passo.titulo} className="rounded-xl border border-border bg-card p-6">
              <span className="flex size-8 items-center justify-center rounded-lg bg-primary font-mono text-sm text-primary-foreground tabular-nums">
                {indice + 1}
              </span>
              <h3 className="mt-4 font-medium">{passo.titulo}</h3>
              <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{passo.texto}</p>
            </li>
          ))}
        </ol>
      </div>
    </section>
  );
}

/* ── Preços ─────────────────────────────────────────────────────────────── */

function Precos({ vitrine }: { vitrine: Awaited<ReturnType<typeof buscarVitrine>> }) {
  return (
    <section id="precos" className="scroll-mt-20 border-b border-border/60">
      <div className="mx-auto max-w-6xl px-5 py-16 lg:py-20">
        <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">Planos</h2>
        <p className="mt-3 max-w-2xl text-muted-foreground">
          O plano decide o volume, nunca o que você pode fazer. Todos os recursos
          estão em todos os planos, inclusive no gratuito.
        </p>

        <div className="mt-10">
          <TabelaDePrecos vitrine={vitrine} />
        </div>
      </div>
    </section>
  );
}

/* ── Perguntas ──────────────────────────────────────────────────────────── */

function Perguntas({ vitrine }: { vitrine: Awaited<ReturnType<typeof buscarVitrine>> }) {
  const perguntas = [
    {
      pergunta: "Preciso de cartão de crédito para testar?",
      resposta:
        vitrine === null
          ? "Não. O cadastro abre a conta na hora, sem cartão, e o plano gratuito não expira."
          : `Não. O cadastro abre a conta na hora e você fica ${vitrine.dias_de_teste} dias com as cotas do plano completo. Quando o teste acaba, a conta é rebaixada para o gratuito — nunca bloqueada, e nada do que você cadastrou é apagado.`,
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
    <section id="perguntas" className="scroll-mt-20 border-b border-border/60 bg-muted/30">
      <div className="mx-auto max-w-3xl px-5 py-16 lg:py-20">
        <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">Perguntas</h2>

        <Accordion type="single" collapsible className="mt-8">
          {perguntas.map((item) => (
            <AccordionItem key={item.pergunta} value={item.pergunta}>
              <AccordionTrigger className="text-left">{item.pergunta}</AccordionTrigger>
              <AccordionContent className="text-sm leading-relaxed text-muted-foreground">
                {item.resposta}
              </AccordionContent>
            </AccordionItem>
          ))}
        </Accordion>
      </div>
    </section>
  );
}

/* ── Fechamento ─────────────────────────────────────────────────────────── */

function Fechamento({ dias }: { dias: number | null }) {
  return (
    <section className="border-b border-border/60">
      <div className="mx-auto max-w-3xl px-5 py-20 text-center lg:py-24">
        <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">
          O próximo orçamento pode sair com o número certo
        </h2>
        <p className="mx-auto mt-4 max-w-xl text-muted-foreground">
          {dias === null
            ? "Crie a conta, cadastre o seu papelão e faça a primeira conta em poucos minutos."
            : `Crie a conta, cadastre o seu papelão e faça a primeira conta em poucos minutos. São ${dias} dias com tudo liberado, sem cartão.`}
        </p>

        <div className="mt-8 flex flex-wrap justify-center gap-3">
          <Link href="/cadastro" className={cn(ESTILO_CTA, "h-12 px-7 text-base")}>
            Criar conta grátis
            <ArrowRight className="size-4" aria-hidden />
          </Link>
          <Link href="/login" className={cn(ESTILO_CTA_SECUNDARIO, "h-12 px-7 text-base")}>
            Já tenho conta
          </Link>
        </div>
      </div>
    </section>
  );
}

/* ── Rodapé ─────────────────────────────────────────────────────────────── */

function Rodape() {
  return (
    <footer className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-5 py-10 text-sm text-muted-foreground">
      <p>© {new Date().getFullYear()} quantoCusta</p>

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

import type { Metadata } from "next";
import { Bricolage_Grotesque, Geist, Geist_Mono } from "next/font/google";
import { Toaster } from "@/components/ui/sonner";
import "./globals.css";

/**
 * As três fontes do produto, hospedadas junto com ele.
 *
 * O `next/font` baixa os arquivos na compilação e os serve do próprio domínio:
 * nenhuma requisição ao Google em tempo de visita, e o CSS gerado já traz as
 * métricas da fonte de reserva, então o texto não muda de lugar quando a
 * definitiva chega.
 *
 * Cada uma tem um trabalho, e só um (ver `@theme` em globals.css):
 *
 *  - Geist desenha o texto. Antes o sistema usava só fontes locais — Helvetica
 *    no macOS, Arial no Windows —, o que é rápido e dá uma cara diferente em
 *    cada máquina;
 *  - Geist Mono desenha os números, com largura fixa: é o que mantém a coluna
 *    de preços alinhada dígito a dígito;
 *  - Bricolage Grotesque desenha os títulos. O eixo `opsz` vem junto porque é
 *    ele que afina o desenho no tamanho de manchete e o engrossa no título de
 *    cartão — sem ele a mesma fonte fica pesada grande ou fraca pequena.
 */
const geist = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

const bricolage = Bricolage_Grotesque({
  variable: "--font-bricolage",
  subsets: ["latin"],
  axes: ["opsz"],
});

export const metadata: Metadata = {
  title: "quantoCusta — Orçamento de embalagens",
  description:
    "Dimensione, precifique e visualize embalagens sob medida em tempo real.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="pt-BR"
      className={`${geist.variable} ${geistMono.variable} ${bricolage.variable} h-full antialiased`}
    >
      {/*
        `suppressHydrationWarning` por causa das EXTENSÕES do navegador, não de
        nada que este projeto renderize.

        ColorZilla injeta `cz-shortcut-listen="true"` no body antes de o React
        hidratar; Grammarly e LastPass fazem o mesmo com atributos próprios. O
        servidor manda um body e o React encontra outro, e o aviso aponta para
        cá — para uma linha que não tem defeito nenhum.

        O escopo é o que torna isso seguro: a supressão vale para os atributos e
        o texto DESTE elemento, e não desce para os filhos. Uma divergência de
        verdade lá dentro — data formatada em fuso diferente, `Math.random()` na
        renderização — continua sendo denunciada normalmente. E a suíte e2e tem
        um check de hidratação que roda num navegador limpo, sem extensão: é ele
        que continua vigiando o que esta linha silencia aqui.
      */}
      <body className="min-h-full flex flex-col" suppressHydrationWarning>
        {children}

        {/*
          Toaster no layout raiz, não na página: as notificações precisam
          sobreviver à navegação entre rotas. O TooltipProvider, ao contrário,
          fica na própria calculadora — só ela usa tooltips.
        */}
        <Toaster richColors position="top-right" />
      </body>
    </html>
  );
}

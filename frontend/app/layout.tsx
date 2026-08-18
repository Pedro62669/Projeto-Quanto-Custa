import type { Metadata } from "next";
import { Geist_Mono } from "next/font/google";
import { Toaster } from "@/components/ui/sonner";
import "./globals.css";

/**
 * Só o monoespaçado é baixado.
 *
 * O texto corrido usa Helvetica (Arial no Windows), que já está na máquina —
 * ver `--font-sans` em globals.css. O Geist Sans vinha sendo baixado a cada
 * visita e nunca chegava à tela, porque a variável que o aplicaria estava
 * quebrada: peso de rede em troca de nada.
 *
 * O mono fica porque desenha os números com largura fixa, e disso a tela
 * depende — é o que mantém a coluna de preços alinhada dígito a dígito.
 */
const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
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
      className={`${geistMono.variable} h-full antialiased`}
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

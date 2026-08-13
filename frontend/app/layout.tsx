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
      <body className="min-h-full flex flex-col">
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

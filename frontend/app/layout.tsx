import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import { Toaster } from "@/components/ui/sonner";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

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
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
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

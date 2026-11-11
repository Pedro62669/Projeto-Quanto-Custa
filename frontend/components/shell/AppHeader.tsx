"use client";

import { useState } from "react";
import dynamic from "next/dynamic";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { ChevronDown, CloudOff, LogOut, Menu, Package } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";

import { session } from "@/lib/auth";
import { useOnline } from "@/hooks/useOnline";
import { useAccount, selectCompanyName } from "@/store/useAccount";
import { Sidebar } from "./Sidebar";

/**
 * Carregado tarde, e é o ponto de todo o arranjo.
 *
 * O import estático deste componente arrastava a `useQuoteStore` — e com ela o
 * motor de precificação, 66 KB — para o pacote da casca, que desce em TODA tela
 * do sistema. Aqui o pacote só é buscado quando o usuário abre a calculadora.
 *
 * `ssr: false` porque ele descreve estado de cliente (o debounce da
 * sincronização) que não existe no servidor; `loading: null` porque o lugar dele
 * no cabeçalho é vazio até haver o que dizer — um esqueleto piscando ali seria
 * mais chamativo que a informação.
 */
const IndicadorDeCalculo = dynamic(
  () => import("./IndicadorDeCalculo").then((m) => m.IndicadorDeCalculo),
  { ssr: false, loading: () => null },
);

/**
 * Cabeçalho da casca.
 *
 * Identifica a EMPRESA, não a ferramenta: quem opera passa o dia aqui e já sabe
 * que sistema está usando — o que ele precisa confirmar num relance é de qual
 * empresa são os números na tela, especialmente quem atende mais de uma.
 */
export function AppHeader() {
  const [menuAberto, setMenuAberto] = useState(false);
  const router = useRouter();

  const empresa = useAccount(selectCompanyName);
  const usuario = useAccount((s) => s.account?.user ?? null);
  const limpaConta = useAccount((s) => s.clear);

  return (
    <header
      data-slot="app-header"
      // Preso no topo e translúcido: o conteúdo passa por baixo e o cabeçalho
      // continua dizendo de qual empresa são os números. `z-30` fica abaixo de
      // diálogos e gavetas (z-50), que precisam cobri-lo.
      className="sticky top-0 z-30 flex h-14 shrink-0 items-center justify-between gap-3 border-b bg-background/85 px-3 backdrop-blur-md sm:px-4"
    >
      <div className="flex min-w-0 items-center gap-2.5">
        {/* Gaveta: a barra lateral inteira, no celular. */}
        <Sheet open={menuAberto} onOpenChange={setMenuAberto}>
          <SheetTrigger asChild>
            <Button variant="ghost" size="icon-sm" className="lg:hidden" aria-label="Abrir menu">
              <Menu />
            </Button>
          </SheetTrigger>

          <SheetContent side="left" className="w-72 overflow-y-auto">
            <SheetTitle className="sr-only">Navegação</SheetTitle>
            <SheetDescription className="sr-only">
              Módulos do sistema
            </SheetDescription>
            <Sidebar onNavigate={() => setMenuAberto(false)} />
          </SheetContent>
        </Sheet>

        {/*
          A marca, como atalho para o painel.

          Mesmo quadrado da página de vendas e do login — tinta com a caixa
          azul. É a única assinatura do produto dentro do sistema; o texto ao
          lado é da EMPRESA, e é ele que a pessoa lê.
        */}
        <Link
          href="/painel"
          aria-label="Painel inicial"
          className="hidden size-8 shrink-0 items-center justify-center rounded-lg bg-primary shadow-xs transition-[rotate] duration-300 hover:-rotate-6 sm:flex"
        >
          <Package className="size-4 text-brand-on-inverted" />
        </Link>

        <div className="min-w-0">
          <h1 className="truncate text-sm font-semibold">
            {empresa ?? "quantoCusta"}
          </h1>
          <p className="truncate text-xs text-muted-foreground">
            Orçamento e controle de cartonagem
          </p>
        </div>
      </div>

      <div className="flex shrink-0 items-center gap-2">
        <SyncIndicator />

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="sm" className="h-9 gap-2 rounded-full pr-2 pl-1">
              {/* As iniciais dizem QUEM está logado num relance — um ícone de
                  pessoa genérico dizia só que alguém estava. */}
              <span
                aria-hidden
                className="flex size-7 items-center justify-center rounded-full bg-brand/12 text-[11px] font-semibold text-brand-strong"
              >
                {iniciais(usuario?.name)}
              </span>
              <span className="hidden max-w-32 truncate sm:inline">
                {usuario?.name ?? "Conta"}
              </span>
              <ChevronDown className="size-3.5 text-muted-foreground" />
            </Button>
          </DropdownMenuTrigger>

          <DropdownMenuContent className="min-w-52">
            <DropdownMenuLabel className="truncate">
              {usuario?.email ?? ""}
            </DropdownMenuLabel>
            <DropdownMenuSeparator />

            <DropdownMenuItem asChild>
              <Link href="/assinatura">Plano e assinatura</Link>
            </DropdownMenuItem>
            <DropdownMenuItem asChild>
              <Link href="/empresa">Dados da empresa</Link>
            </DropdownMenuItem>

            <DropdownMenuSeparator />

            <DropdownMenuItem
              variant="destructive"
              onSelect={async () => {
                await session.logout();

                // Limpa o retrato da sessão: sem isso, o próximo login mostraria
                // o nome da empresa anterior até a chamada nova responder.
                limpaConta();
                router.replace("/login");
              }}
            >
              <LogOut />
              Sair
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </header>
  );
}

/**
 * As iniciais de um nome: a primeira letra do primeiro e do último.
 *
 * Do último, e não do segundo: em "Maria das Dores Silva" o segundo nome é
 * "das", e "MD" não é como ninguém assina.
 */
function iniciais(nome: string | undefined): string {
  const partes = (nome ?? "").trim().split(/\s+/).filter(Boolean);

  if (partes.length === 0) return "–";
  if (partes.length === 1) return partes[0].slice(0, 2).toUpperCase();

  return (partes[0][0] + partes[partes.length - 1][0]).toUpperCase();
}

/**
 * Estado da conexão e do cálculo.
 *
 * ## Por que são dois componentes, e não um
 *
 * Eram um só, e o import custava caro em toda tela do sistema.
 *
 * O indicador de cálculo lê a `useQuoteStore`, e a store importa o motor de
 * precificação — 66 KB de matemática de cartonagem. Como o import é ESTÁTICO, o
 * empacotador não tem como saber que o componente só desenha em `/calculadora`:
 * o motor entrava no pacote da casca e descia junto com a lista de orçamentos, a
 * tela de clientes, o financeiro, todas.
 *
 * Medido: 66 KB de código que aquelas telas nunca executam, em toda visita.
 *
 * O aviso de OFFLINE não pode ir junto para dentro do carregamento tardio — ele
 * vale em qualquer tela, e é justamente quando a rede cai que não dá para contar
 * com um pedaço de JavaScript ainda por baixar. Por isso ele fica aqui, estático
 * e sem dependência nenhuma de store.
 */
function SyncIndicator() {
  const caminho = usePathname();
  const online = useOnline();

  if (!online) {
    return (
      <span
        aria-live="polite"
        className="flex items-center gap-1.5 rounded-md bg-destructive/10 px-2 py-1 text-[11px] font-medium text-destructive"
      >
        <CloudOff className="size-3.5" />
        sem conexão
      </span>
    );
  }

  /*
   * Só na calculadora. É o único lugar do sistema onde a tela calcula sozinha,
   * em debounce, e o usuário precisa saber se o número que está lendo já foi
   * confirmado pelo servidor. Nas outras telas o dado é gravado por ação
   * explícita, e um "em dia com o servidor" permanente seria enfeite — pior,
   * um enfeite aceso descrevendo o cálculo de outra tela.
   */
  if (caminho !== "/calculadora") return null;

  return <IndicadorDeCalculo />;
}

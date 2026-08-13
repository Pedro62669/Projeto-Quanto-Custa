"use client";

import { useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useShallow } from "zustand/react/shallow";
import { CloudOff, Loader2, LogOut, Menu, User } from "lucide-react";

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
import { useQuoteStore } from "@/store/useQuoteStore";
import { Sidebar } from "./Sidebar";

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
      className="flex h-14 shrink-0 items-center justify-between gap-3 border-b px-3 sm:px-4"
    >
      <div className="flex min-w-0 items-center gap-2">
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
            <Button variant="ghost" size="sm" className="gap-1.5">
              <User className="size-4" />
              <span className="hidden max-w-32 truncate sm:inline">
                {usuario?.name ?? "Conta"}
              </span>
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
 * Estado da sincronia — só na calculadora.
 *
 * É o único lugar do sistema onde a tela calcula sozinha, em debounce, e o
 * usuário precisa saber se o número que está lendo já foi confirmado pelo
 * servidor. Nas outras telas o dado é gravado por ação explícita, e um "em dia
 * com o servidor" permanente no cabeçalho seria enfeite — pior, seria um
 * enfeite que continuaria aceso descrevendo um cálculo de outra tela.
 */
function SyncIndicator() {
  const caminho = usePathname();

  const { isSyncing, isConfirmed } = useQuoteStore(
    useShallow((s) => ({ isSyncing: s.isSyncing, isConfirmed: s.confirmed !== null })),
  );

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

  if (caminho !== "/calculadora") return null;

  if (isSyncing) {
    return (
      <span
        aria-live="polite"
        className="flex items-center gap-1.5 text-[11px] text-muted-foreground"
      >
        <Loader2 className="size-3.5 animate-spin" />
        calculando
      </span>
    );
  }

  if (!isConfirmed) return null;

  return (
    <span
      aria-live="polite"
      className="flex items-center gap-1.5 text-[11px] text-muted-foreground"
    >
      <span className="size-1.5 rounded-full bg-emerald-500" aria-hidden />
      em dia com o servidor
    </span>
  );
}

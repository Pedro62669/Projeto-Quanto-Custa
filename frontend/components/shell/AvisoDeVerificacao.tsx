"use client";

import { useState } from "react";
import { MailWarning } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { api } from "@/lib/api";
import { mensagemDeErro } from "@/hooks/useApi";
import { useAccount } from "@/store/useAccount";

/**
 * Aviso de e-mail não confirmado.
 *
 * Uma faixa, não um bloqueio: a conta funciona inteira sem a confirmação. Ela
 * só é exigida para assinar — porque é para aquele endereço que vão o recibo e
 * a cobrança, e cobrar de quem não conseguimos alcançar é o começo de um
 * problema que ninguém quer.
 *
 * Some sozinha quando o e-mail é confirmado: a faixa lê o mesmo `/api/me` que a
 * casca já carrega, então não há um segundo estado para ficar desatualizado.
 */
export function AvisoDeVerificacao() {
  const usuario = useAccount((s) => s.account?.user ?? null);
  const [enviando, setEnviando] = useState(false);
  const [reenviado, setReenviado] = useState(false);

  if (!usuario || usuario.email_verified) return null;

  return (
    <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-b border-amber-500/30 bg-amber-500/10 px-4 py-1.5 text-xs text-amber-800 dark:text-amber-400">
      <span className="flex items-center gap-1.5">
        <MailWarning className="size-3.5 shrink-0" />
        Confirme <strong className="font-medium">{usuario.email}</strong> para poder
        assinar um plano.
      </span>

      <Button
        variant="ghost"
        size="xs"
        disabled={enviando || reenviado}
        className="h-6 text-amber-900 hover:bg-amber-500/20 dark:text-amber-300"
        onClick={async () => {
          setEnviando(true);

          try {
            await api.auth.resendVerification();

            // Bloqueia o reenvio depois do primeiro: o backend limita a taxa, e
            // um segundo clique só renderia um 429 na cara do usuário.
            setReenviado(true);
            toast.success("E-mail de confirmação reenviado");
          } catch (erro) {
            toast.error(mensagemDeErro(erro));
          } finally {
            setEnviando(false);
          }
        }}
      >
        {reenviado ? "Enviado" : "Reenviar"}
      </Button>
    </div>
  );
}

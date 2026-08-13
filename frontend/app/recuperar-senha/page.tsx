"use client";

import { useState } from "react";
import Link from "next/link";
import { CheckCircle2, Loader2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { TelaPublica } from "@/components/auth/TelaPublica";
import { FormError, TextField } from "@/components/form/Field";

import { api } from "@/lib/api";
import { mensagemDeErro } from "@/hooks/useApi";

/**
 * Pedido de redefinição de senha.
 *
 * A resposta do servidor é NEUTRA de propósito: e-mail cadastrado e não
 * cadastrado devolvem a mesma mensagem. A tela precisa preservar essa neutralidade
 * — mostrar "não encontramos esse e-mail" transformaria o formulário num
 * detector de quem tem conta no sistema, que é exatamente o que o backend evita.
 */
export default function RecuperarSenhaPage() {
  const [email, setEmail] = useState("");
  const [enviado, setEnviado] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [erro, setErro] = useState<string | null>(null);

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();

    setEnviando(true);
    setErro(null);

    try {
      await api.auth.forgotPassword(email);
      setEnviado(true);
    } catch (e) {
      setErro(mensagemDeErro(e));
    } finally {
      setEnviando(false);
    }
  }

  return (
    <TelaPublica
      titulo="Recuperar acesso"
      descricao={
        enviado ? undefined : "Informe o e-mail da conta e enviamos um link de redefinição."
      }
      rodape={
        <Link
          href="/login"
          className="text-muted-foreground underline-offset-4 hover:underline"
        >
          Voltar para o login
        </Link>
      }
    >
      {enviado ? (
        <div className="space-y-3 text-center">
          <CheckCircle2 className="mx-auto size-8 text-emerald-600 dark:text-emerald-500" />
          <p className="text-sm font-medium">Pedido registrado</p>
          <p className="text-sm text-muted-foreground">
            Se houver uma conta com <strong className="font-medium">{email}</strong>,
            o link de redefinição chega em instantes. Ele vale por 60 minutos.
          </p>
          <p className="text-xs text-muted-foreground">
            Confira a caixa de spam antes de pedir de novo.
          </p>
        </div>
      ) : (
        <form onSubmit={enviar} className="space-y-4">
          <FormError message={erro} />

          <TextField
            label="E-mail"
            name="email"
            type="email"
            required
            value={email}
            onChange={setEmail}
          />

          <Button type="submit" className="w-full" disabled={enviando || !email}>
            {enviando && <Loader2 className="size-4 animate-spin" />}
            Enviar link
          </Button>
        </form>
      )}
    </TelaPublica>
  );
}

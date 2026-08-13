"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { TelaPublica } from "@/components/auth/TelaPublica";
import { FormError, TextField } from "@/components/form/Field";
import type { FieldErrors } from "@/components/form/Field";

import { api, ApiError } from "@/lib/api";
import { mensagemDeErro } from "@/hooks/useApi";

/**
 * Redefinição de senha, a partir do link do e-mail.
 *
 * `useSearchParams` exige uma fronteira de Suspense nesta versão do Next — sem
 * ela a rota inteira vira dinâmica no build. O conteúdo real vive no
 * componente interno.
 */
export default function RedefinirSenhaPage() {
  return (
    <Suspense fallback={<Skeleton className="h-dvh w-full" />}>
      <Formulario />
    </Suspense>
  );
}

function Formulario() {
  const parametros = useSearchParams();
  const router = useRouter();

  const token = parametros.get("token") ?? "";
  const emailDoLink = parametros.get("email") ?? "";

  const [email, setEmail] = useState(emailDoLink);
  const [password, setPassword] = useState("");
  const [confirmacao, setConfirmacao] = useState("");
  const [errors, setErrors] = useState<FieldErrors>({});
  const [erroGeral, setErroGeral] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();

    setEnviando(true);
    setErrors({});
    setErroGeral(null);

    try {
      await api.auth.resetPassword({
        token,
        email,
        password,
        password_confirmation: confirmacao,
      });

      // O servidor revoga todas as sessões ao redefinir — inclusive a de quem
      // roubou a senha. Por isso o caminho daqui é o login, e não o sistema.
      toast.success("Senha redefinida", {
        description: "Todas as sessões abertas foram encerradas. Entre com a senha nova.",
      });

      router.push("/login");
    } catch (erro) {
      if (erro instanceof ApiError && Object.keys(erro.errors).length > 0) {
        setErrors(erro.errors);
      } else {
        setErroGeral(mensagemDeErro(erro));
      }
    } finally {
      setEnviando(false);
    }
  }

  if (!token) {
    return (
      <TelaPublica
        titulo="Link inválido"
        descricao="Este endereço não tem um token de redefinição."
        rodape={
          <Link href="/recuperar-senha" className="underline-offset-4 hover:underline">
            Pedir um link novo
          </Link>
        }
      >
        <p className="text-sm text-muted-foreground">
          Abra o link direto do e-mail que você recebeu. Ele vale por 60 minutos e
          só pode ser usado uma vez.
        </p>
      </TelaPublica>
    );
  }

  return (
    <TelaPublica titulo="Nova senha" descricao="Escolha a senha que vai usar a partir de agora.">
      <form onSubmit={enviar} className="space-y-4">
        <FormError message={erroGeral} />

        <TextField
          label="E-mail"
          name="email"
          type="email"
          required
          value={email}
          onChange={setEmail}
          errors={errors}
        />

        <TextField
          label="Nova senha"
          name="password"
          type="password"
          required
          value={password}
          onChange={setPassword}
          errors={errors}
          hint="Mínimo de 8 caracteres."
        />

        <TextField
          label="Repita a senha"
          name="password_confirmation"
          type="password"
          required
          value={confirmacao}
          onChange={setConfirmacao}
          errors={errors}
        />

        <Button type="submit" className="w-full" disabled={enviando}>
          {enviando && <Loader2 className="size-4 animate-spin" />}
          Redefinir senha
        </Button>
      </form>
    </TelaPublica>
  );
}

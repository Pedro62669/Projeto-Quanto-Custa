"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { TelaPublica } from "@/components/auth/TelaPublica";

import { session } from "@/lib/auth";
import { ApiError } from "@/lib/api";

/**
 * Tela de login.
 *
 * Deliberadamente mínima: uma porta de entrada, não um produto. Toda a
 * complexidade do sistema mora na calculadora.
 */
export default function LoginPage() {
  const router = useRouter();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setIsSubmitting(true);
    setError(null);

    try {
      await session.login(email, password);
      router.push("/painel");
    } catch (err) {
      // A API devolve uma mensagem genérica de propósito (não distingue
      // "e-mail inexistente" de "senha errada"); apenas a repassamos.
      setError(
        err instanceof ApiError
          ? (err.errors.email?.[0] ?? err.message)
          : "Não foi possível conectar ao servidor.",
      );
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    // A mesma moldura do cadastro e da recuperação de senha. O login trazia
    // uma cópia própria dela, e foi a única das quatro telas que ficou para
    // trás quando a moldura mudou — que é exatamente o que cópia faz.
    <TelaPublica
      titulo="quantoCusta"
      descricao="Orçamento e precificação de embalagens sob medida"
      rodape={
        /* As duas saídas que faltavam: quem esqueceu a senha ficava sem
           caminho, e quem ainda não tem empresa não tinha por onde criar uma. */
        <div className="space-y-2">
          <p>
            <Link
              href="/recuperar-senha"
              className="text-muted-foreground underline-offset-4 hover:underline"
            >
              Esqueci minha senha
            </Link>
          </p>
          <p className="text-muted-foreground">
            Ainda não tem conta?{" "}
            <Link
              href="/cadastro"
              className="font-medium text-foreground underline-offset-4 hover:underline"
            >
              Cadastre sua empresa
            </Link>
          </p>
        </div>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="email">E-mail</Label>
          <Input
            id="email"
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            aria-invalid={Boolean(error)}
          />
        </div>

        <div className="space-y-2">
          <Label htmlFor="password">Senha</Label>
          <Input
            id="password"
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            aria-invalid={Boolean(error)}
          />
        </div>

        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}

        <Button type="submit" size="lg" className="w-full" disabled={isSubmitting}>
          {isSubmitting && <Loader2 className="size-4 animate-spin" />}
          Entrar
        </Button>
      </form>
    </TelaPublica>
  );
}

"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Loader2, Package } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent } from "@/components/ui/card";

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
    <main className="flex min-h-dvh items-center justify-center bg-muted/30 p-6">
      <div className="w-full max-w-sm space-y-6">
        <header className="space-y-2 text-center">
          <div className="mx-auto flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground">
            <Package className="size-5" />
          </div>
          <h1 className="text-xl font-semibold tracking-tight">quantoCusta</h1>
          <p className="text-sm text-muted-foreground">
            Orçamento e precificação de embalagens sob medida
          </p>
        </header>

        <Card>
          <CardContent className="p-6">
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

              <Button type="submit" className="w-full" disabled={isSubmitting}>
                {isSubmitting && <Loader2 className="size-4 animate-spin" />}
                Entrar
              </Button>
            </form>
          </CardContent>
        </Card>

        {/* As duas saídas que faltavam: quem esqueceu a senha ficava sem
            caminho, e quem ainda não tem empresa não tinha por onde criar uma. */}
        <div className="space-y-2 text-center text-sm">
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
            <Link href="/cadastro" className="font-medium text-foreground underline-offset-4 hover:underline">
              Cadastre sua empresa
            </Link>
          </p>
        </div>
      </div>
    </main>
  );
}

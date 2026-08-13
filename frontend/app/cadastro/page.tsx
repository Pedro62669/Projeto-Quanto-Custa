"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { TelaPublica } from "@/components/auth/TelaPublica";
import { FormError, TextField } from "@/components/form/Field";
import type { FieldErrors } from "@/components/form/Field";

import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/auth";
import { mensagemDeErro } from "@/hooks/useApi";

/**
 * Cadastro público de empresa.
 *
 * Antes desta tela, uma empresa só nascia por seeder ou tinker — planos, cotas,
 * cobrança e reembolso estavam prontos e ninguém conseguia virar assinante
 * sozinho.
 *
 * O servidor provisiona tudo numa transação: empresa, usuário admin, parâmetros
 * de custo padrão e quatro materiais iniciais. Sem os parâmetros, a primeira
 * visita à calculadora quebraria — uma conta nova precisa nascer calculando.
 */
export default function CadastroPage() {
  const router = useRouter();

  const [form, setForm] = useState({
    empresa: "",
    nome: "",
    email: "",
    documento: "",
    password: "",
    password_confirmation: "",
  });

  const [errors, setErrors] = useState<FieldErrors>({});
  const [erroGeral, setErroGeral] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);

  const define = (campo: keyof typeof form, valor: string) =>
    setForm((atual) => ({ ...atual, [campo]: valor }));

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();

    setEnviando(true);
    setErrors({});
    setErroGeral(null);

    try {
      const { token, user } = await api.auth.register({
        ...form,
        documento: form.documento || undefined,
      });

      // O cadastro já devolve o token: a confirmação do e-mail acontece em
      // paralelo, por link, e não bloqueia a primeira visita ao sistema.
      session.adopt(token, user);

      router.push("/painel");
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

  return (
    <TelaPublica
      titulo="Cadastre sua empresa"
      descricao="Três dias de teste no plano completo. Depois, a conta continua funcionando no plano gratuito — nada é bloqueado."
      rodape={
        <p className="text-muted-foreground">
          Já tem conta?{" "}
          <Link href="/login" className="font-medium text-foreground underline-offset-4 hover:underline">
            Entrar
          </Link>
        </p>
      }
      largura="max-w-md"
    >
      <form onSubmit={enviar} className="space-y-4">
        <FormError message={erroGeral} />

        <TextField
          label="Nome da empresa"
          name="empresa"
          required
          value={form.empresa}
          onChange={(v) => define("empresa", v)}
          errors={errors}
          placeholder="Cartonagem Silva"
        />

        <TextField
          label="CNPJ ou CPF"
          name="documento"
          value={form.documento}
          onChange={(v) => define("documento", v)}
          errors={errors}
          hint="Opcional agora — pode completar depois, nos dados da empresa."
        />

        <div className="grid gap-3 sm:grid-cols-2">
          <TextField
            label="Seu nome"
            name="nome"
            required
            value={form.nome}
            onChange={(v) => define("nome", v)}
            errors={errors}
          />
          <TextField
            label="Seu e-mail"
            name="email"
            type="email"
            required
            value={form.email}
            onChange={(v) => define("email", v)}
            errors={errors}
          />
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <TextField
            label="Senha"
            name="password"
            type="password"
            required
            value={form.password}
            onChange={(v) => define("password", v)}
            errors={errors}
            hint="Mínimo de 8 caracteres."
          />
          <TextField
            label="Repita a senha"
            name="password_confirmation"
            type="password"
            required
            value={form.password_confirmation}
            onChange={(v) => define("password_confirmation", v)}
            errors={errors}
          />
        </div>

        <Button type="submit" className="w-full" disabled={enviando}>
          {enviando && <Loader2 className="size-4 animate-spin" />}
          Criar conta
        </Button>

        <p className="text-center text-xs text-muted-foreground">
          Você recebe um e-mail para confirmar o endereço. Dá para usar o sistema
          antes disso — a confirmação só é exigida na hora de assinar.
        </p>
      </form>
    </TelaPublica>
  );
}

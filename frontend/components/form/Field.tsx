"use client";

import { useId } from "react";

import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

/**
 * Campo de formulário ligado aos erros do SERVIDOR.
 *
 * O sistema não valida no cliente de propósito. As regras já existem, escritas
 * uma vez, nas Request do Laravel — repeti-las aqui criaria uma segunda verdade
 * que divergiria da primeira no dia em que alguém mudasse um limite só de um
 * lado. A tela envia, o servidor recusa com `{campo: [mensagens]}`, e cada
 * campo mostra a sua.
 *
 * O custo dessa escolha é uma ida ao servidor para descobrir o erro. É um custo
 * aceitável: os formulários daqui são de cadastro, não de digitação em massa.
 */
export interface FieldErrors {
  [campo: string]: string[] | undefined;
}

export function Field({
  label,
  name,
  errors,
  hint,
  required,
  className = "",
  children,
}: {
  label: string;
  /** Nome do campo NA API — é a chave que casa com o erro devolvido. */
  name?: string;
  errors?: FieldErrors;
  hint?: string;
  required?: boolean;
  className?: string;
  children: (props: { id: string; "aria-invalid"?: boolean }) => React.ReactNode;
}) {
  const id = useId();
  const erro = name ? errors?.[name]?.[0] : undefined;

  return (
    <div className={`min-w-0 space-y-1.5 ${className}`}>
      <Label htmlFor={id} className="block text-xs">
        {label}
        {required && (
          <span className="text-muted-foreground" aria-hidden>
            {" "}
            *
          </span>
        )}
      </Label>

      {children({ id, "aria-invalid": erro ? true : undefined })}

      {/*
        O erro do servidor substitui a dica: mostrar os dois empilha texto no
        momento em que o usuário mais precisa de uma instrução só.
      */}
      {erro ? (
        <p className="text-xs text-destructive">{erro}</p>
      ) : hint ? (
        <p className="text-xs text-muted-foreground">{hint}</p>
      ) : null}
    </div>
  );
}

/** Campo de texto — o caso mais comum, já montado. */
export function TextField({
  label,
  name,
  value,
  onChange,
  errors,
  hint,
  required,
  type = "text",
  placeholder,
  maxLength,
  className,
}: {
  label: string;
  name: string;
  value: string;
  onChange: (valor: string) => void;
  errors?: FieldErrors;
  hint?: string;
  required?: boolean;
  type?: string;
  placeholder?: string;
  maxLength?: number;
  className?: string;
}) {
  return (
    <Field
      label={label}
      name={name}
      errors={errors}
      hint={hint}
      required={required}
      className={className}
    >
      {(props) => (
        <Input
          {...props}
          type={type}
          value={value}
          placeholder={placeholder}
          maxLength={maxLength}
          onChange={(e) => onChange(e.target.value)}
        />
      )}
    </Field>
  );
}

/**
 * Campo numérico.
 *
 * `value || ""` pelo mesmo motivo dos campos de dimensão da calculadora: sem
 * isso, apagar o conteúdo mostra "0" e obriga o usuário a selecionar e
 * sobrescrever. Vazio vira `null`, não zero — em custo de material, zero é um
 * preço e null é "não informado", e a diferença muda o cálculo.
 */
export function NumberField({
  label,
  name,
  value,
  onChange,
  errors,
  hint,
  required,
  min,
  max,
  step = "any",
  suffix,
  className,
}: {
  label: string;
  name: string;
  value: number | null;
  onChange: (valor: number | null) => void;
  errors?: FieldErrors;
  hint?: string;
  required?: boolean;
  min?: number;
  max?: number;
  step?: number | "any";
  suffix?: string;
  className?: string;
}) {
  return (
    <Field
      label={suffix ? `${label} (${suffix})` : label}
      name={name}
      errors={errors}
      hint={hint}
      required={required}
      className={className}
    >
      {(props) => (
        <Input
          {...props}
          type="number"
          inputMode="decimal"
          min={min}
          max={max}
          step={step}
          value={value ?? ""}
          onChange={(e) => {
            const bruto = e.target.value;
            onChange(bruto === "" ? null : Number(bruto));
          }}
          className="font-mono tabular-nums"
        />
      )}
    </Field>
  );
}

/**
 * Erro que não pertence a campo nenhum.
 *
 * Regras de negócio do servidor (`errors.pricing`, `errors.plan`) e falhas de
 * rede não têm onde aparecer num formulário campo a campo — e desaparecer em
 * silêncio é o pior destino para elas.
 */
export function FormError({ message }: { message: string | null }) {
  if (!message) return null;

  return (
    <p role="alert" className="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
      {message}
    </p>
  );
}

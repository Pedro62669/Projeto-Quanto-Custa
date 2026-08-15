"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Loader2, Save } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { toast } from "sonner";

import { api, ApiError } from "@/lib/api";
import { SeletorDeCliente } from "@/components/cadastro/SeletorDeCliente";
import type { Reabertura } from "@/hooks/useReabrirOrcamento";
import { useQuoteStore } from "@/store/useQuoteStore";

/**
 * O formulário zerado, num lugar só.
 *
 * Ele é usado no estado inicial e na limpeza depois de salvar; escrever o
 * literal duas vezes é como um campo novo entra em uma das duas e some da
 * outra — e o resíduo aparece no orçamento seguinte.
 */
const FORMULARIO_VAZIO = {
  client_id: null as number | null,
  client_name: "",
  client_email: "",
  notes: "",
};

/**
 * Captura os dados do cliente e persiste o orçamento.
 *
 * Envia apenas a ESPECIFICAÇÃO — nunca os valores calculados. O servidor
 * recalcula tudo antes de gravar, de modo que adulterar o payload no navegador
 * não altera o preço registrado.
 */
export function SaveQuoteDialog({
  disabled,
  reabertura,
}: {
  disabled?: boolean;
  reabertura?: Reabertura | null;
}) {
  const spec = useQuoteStore((s) => s.spec);
  const router = useRouter();

  // Editar grava por cima e não pede o cliente de novo — ele já está no
  // orçamento, e reapresentar o campo convidaria a trocá-lo por engano.
  const editando = reabertura?.modo === "editar";

  const [open, setOpen] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [form, setForm] = useState(FORMULARIO_VAZIO);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setIsSaving(true);
    setFieldErrors({});

    try {
      /*
       * Editar grava por cima e volta para o orçamento; duplicar e criar
       * seguem pelo POST de sempre.
       *
       * A edição usa endpoint próprio (`/specification`) porque substitui a
       * especificação INTEIRA — o `update` do recurso aceita campo solto, e um
       * PUT sem `components` apagaria a ferragem em silêncio.
       */
      if (editando && reabertura) {
        await api.quotes.revise(reabertura.id, spec);

        toast.success(`Orçamento ${reabertura.referencia} atualizado`);
        setOpen(false);
        router.push(`/orcamentos/${reabertura.id}`);

        return;
      }

      const quote = await api.quotes.create(spec, form);

      // O atalho para o registro recém-criado, e não só um aviso: o orçamento
      // acabou de ganhar ficha técnica, PDF e aprovação — e a hora de usá-los é
      // agora, com o trabalho fresco, não depois de procurá-lo na lista.
      toast.success(`Orçamento ${quote.reference} salvo`, {
        description: "Ficha técnica, PDF e aprovação já estão disponíveis.",
        action: {
          label: "Abrir",
          onClick: () => router.push(`/orcamentos/${quote.id}`),
        },
      });

      setOpen(false);
      setForm(FORMULARIO_VAZIO);
    } catch (error) {
      if (error instanceof ApiError) {
        // Erros de validação vão para os campos; o resto vira toast.
        setFieldErrors(error.errors);
        if (Object.keys(error.errors).length === 0) toast.error(error.message);
      } else {
        toast.error("Não foi possível salvar o orçamento.");
      }
    } finally {
      setIsSaving(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button className="w-full" size="lg" disabled={disabled}>
          <Save className="size-4" />
          {editando ? "Gravar alterações" : "Salvar orçamento"}
        </Button>
      </DialogTrigger>

      <DialogContent className="sm:max-w-md">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle>
              {editando
                ? `Gravar sobre ${reabertura?.referencia}`
                : "Salvar orçamento"}
            </DialogTitle>
            <DialogDescription>
              {editando
                ? "O orçamento é recalculado e o valor anterior é substituído. Só rascunho pode ser editado."
                : "Os valores serão recalculados e congelados pelo servidor no momento da gravação."}
            </DialogDescription>
          </DialogHeader>

          {/* Editando, o cliente já está gravado no orçamento. Reapresentar os
              campos convidaria a trocá-lo por engano ao corrigir uma medida. */}
          {!editando && (
          <div className="space-y-4 py-4">
            <SeletorDeCliente
              clienteId={form.client_id}
              nome={form.client_name}
              onChange={({ clienteId, nome }) =>
                setForm({ ...form, client_id: clienteId, client_name: nome })
              }
            />

            {fieldErrors.client_name?.map((mensagem) => (
              <p key={mensagem} className="text-xs text-destructive">
                {mensagem}
              </p>
            ))}

            <Field
              id="client_email"
              label="E-mail"
              type="email"
              value={form.client_email}
              onChange={(client_email) => setForm({ ...form, client_email })}
              errors={fieldErrors.client_email}
            />

            <div className="space-y-2">
              <Label htmlFor="notes">Observações</Label>
              <Textarea
                id="notes"
                rows={3}
                value={form.notes}
                onChange={(e) => setForm({ ...form, notes: e.target.value })}
              />
            </div>
          </div>
          )}

          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
              Cancelar
            </Button>
            {/* Sem cliente não há orçamento novo — mas editando o cliente já
                está gravado, e exigi-lo travaria o botão para sempre. */}
            <Button type="submit" disabled={isSaving || (!editando && !form.client_name)}>
              {isSaving && <Loader2 className="size-4 animate-spin" />}
              {editando ? "Gravar" : "Salvar"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function Field({
  id,
  label,
  value,
  onChange,
  errors,
  type = "text",
  required,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  errors?: string[];
  type?: string;
  required?: boolean;
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={id}>
        {label}
        {required && <span className="text-destructive"> *</span>}
      </Label>
      <Input
        id={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={Boolean(errors?.length)}
      />
      {errors?.map((message) => (
        <p key={message} className="text-xs text-destructive">
          {message}
        </p>
      ))}
    </div>
  );
}

"use client";

import { useState } from "react";
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
import { useQuoteStore } from "@/store/useQuoteStore";

/**
 * Captura os dados do cliente e persiste o orçamento.
 *
 * Envia apenas a ESPECIFICAÇÃO — nunca os valores calculados. O servidor
 * recalcula tudo antes de gravar, de modo que adulterar o payload no navegador
 * não altera o preço registrado.
 */
export function SaveQuoteDialog({ disabled }: { disabled?: boolean }) {
  const spec = useQuoteStore((s) => s.spec);

  const [open, setOpen] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [form, setForm] = useState({ client_name: "", client_email: "", notes: "" });
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setIsSaving(true);
    setFieldErrors({});

    try {
      const quote = await api.saveQuote(spec, form);

      toast.success(`Orçamento ${quote.reference} salvo`, {
        description: "Disponível no histórico.",
      });

      setOpen(false);
      setForm({ client_name: "", client_email: "", notes: "" });
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
          Salvar orçamento
        </Button>
      </DialogTrigger>

      <DialogContent className="sm:max-w-md">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle>Salvar orçamento</DialogTitle>
            <DialogDescription>
              Os valores serão recalculados e congelados pelo servidor no momento
              da gravação.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-4">
            <Field
              id="client_name"
              label="Cliente"
              required
              value={form.client_name}
              onChange={(client_name) => setForm({ ...form, client_name })}
              errors={fieldErrors.client_name}
            />
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

          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
              Cancelar
            </Button>
            <Button type="submit" disabled={isSaving || !form.client_name}>
              {isSaving && <Loader2 className="size-4 animate-spin" />}
              Salvar
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

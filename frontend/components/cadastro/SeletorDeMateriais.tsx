"use client";

import { Loader2 } from "lucide-react";

import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { useApi } from "@/hooks/useApi";
import { api } from "@/lib/api";

/**
 * "O que este fornecedor vende."
 *
 * Caixas de seleção sobre os materiais JÁ cadastrados, e não um campo de texto.
 * A diferença aparece na pergunta que a empresa realmente faz — "acabou o
 * papelão E, quem me vende?" —, que texto livre não responde: ele guarda
 * "Papelão E" e "papelao e 1,5mm" como coisas diferentes, e nenhuma delas se
 * cruza com o material que a calculadora usa.
 *
 * A lista vem de `/materials`, que devolve só os ATIVOS. É o comportamento certo
 * aqui: não faz sentido oferecer um material que a empresa parou de comprar.
 * Um vínculo antigo com material desativado continua gravado no banco — só não
 * aparece para ser marcado de novo.
 */
export function SeletorDeMateriais({
  selecionados,
  onChange,
}: {
  selecionados: number[];
  onChange: (ids: number[]) => void;
}) {
  const materiais = useApi("materiais:seletor", () => api.pricing.materials());

  function alterna(id: number, marcado: boolean) {
    onChange(
      marcado ? [...selecionados, id] : selecionados.filter((atual) => atual !== id),
    );
  }

  return (
    <fieldset className="space-y-2">
      <legend className="text-sm font-medium">O que ele fornece</legend>

      {materiais.loading && (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 className="size-3.5 animate-spin" />
          Carregando materiais…
        </p>
      )}

      {materiais.error && (
        <p className="text-sm text-destructive">
          Não foi possível carregar os materiais. O fornecedor pode ser salvo
          assim mesmo — o vínculo entra depois.
        </p>
      )}

      {materiais.data && materiais.data.length === 0 && (
        <p className="text-sm text-muted-foreground">
          Nenhum material cadastrado ainda. Cadastre o papelão que você compra em
          Materiais e volte aqui para ligar os dois.
        </p>
      )}

      {materiais.data && materiais.data.length > 0 && (
        // Altura limitada com rolagem própria: a lista cresce sem teto no plano
        // Profissional, e sessenta itens empurrariam o botão de salvar para
        // fora da gaveta.
        <div className="max-h-56 space-y-1 overflow-y-auto rounded-lg border p-2">
          {materiais.data.map((material) => {
            const id = `material-${material.id}`;
            const marcado = selecionados.includes(material.id);

            return (
              <div
                key={material.id}
                className="flex items-center gap-2.5 rounded-md px-2 py-1.5 hover:bg-muted/50"
              >
                <Checkbox
                  id={id}
                  checked={marcado}
                  onCheckedChange={(valor) => alterna(material.id, valor === true)}
                />
                {/* O `min-w-0` no span, e não só no Label: o Label do shadcn é
                    `display:flex`, e sem ele o filho não encolhe — o nome longo
                    esticaria a linha em vez de cortar com reticências. */}
                <Label htmlFor={id} className="min-w-0 flex-1 cursor-pointer font-normal">
                  <span className="min-w-0 truncate">{material.name}</span>
                </Label>
              </div>
            );
          })}
        </div>
      )}

      <p className="text-xs text-muted-foreground">
        Marque tudo que você compra dele. Serve para achar o fornecedor quando o
        material acabar.
      </p>
    </fieldset>
  );
}

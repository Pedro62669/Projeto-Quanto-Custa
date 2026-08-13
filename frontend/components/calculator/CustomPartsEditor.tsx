"use client";

import { useShallow } from "zustand/react/shallow";
import { Layers, Plus, TriangleAlert, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent } from "@/components/ui/card";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

import {
  MAX_PECAS,
  materiaisParaPeca,
  selectCustomParts,
  useQuoteStore,
} from "@/store/useQuoteStore";
import { customPartsConsumption, formatCurrency } from "@/lib/pricing/engine";
import type { ComponentRole, CustomPartInput, Material } from "@/lib/pricing/types";

/**
 * Editor das peças do modelo livre.
 *
 * Substitui os três campos de dimensão porque a caixa aqui não tem equação: ela
 * é a lista de retângulos que o usuário mediu na bancada. Cada linha carrega o
 * PRÓPRIO material, e daí a própria perda — um mesmo projeto mistura papelão
 * cinza a 12%, kraft a 8% e tecido a 15%, e um percentual único do orçamento
 * subestimaria justamente o que mais desperdiça.
 *
 * O custo por peça aparece na linha, e sai de `customPartsConsumption()` — a
 * mesma função que o motor usa para precificar. Recalculá-lo aqui com a fórmula
 * "na mão" criaria uma terceira aritmética para divergir das outras duas.
 */
export function CustomPartsEditor() {
  const { parts, materials, currency, addCustomPart } = useQuoteStore(
    useShallow((s) => ({
      parts: selectCustomParts(s),
      materials: s.materials,
      currency: s.currency,
      addCustomPart: s.addCustomPart,
    })),
  );

  const temMaterialComArea = materiaisParaPeca(materials, "structure").length > 0;

  if (!temMaterialComArea) {
    return (
      <Card className="border-amber-500/50 bg-amber-500/5">
        <CardContent className="flex items-start gap-2 p-3 text-xs text-amber-700 dark:text-amber-500">
          <TriangleAlert className="mt-0.5 size-4 shrink-0" />
          <span>
            O modelo livre precisa de ao menos um material medido em área (m² ou
            kg). Ímã e espuma são contados e comprados em bloco — não dá para
            cortar uma peça de 200 × 300 mm deles.
          </span>
        </CardContent>
      </Card>
    );
  }

  // Só com peça: a função recusa lista vazia, como o motor do servidor recusa.
  const consumo = parts.length > 0 ? customPartsConsumption(parts) : null;

  const areaPorCaixa = consumo
    ? consumo.structureGrossM2 + consumo.wrapGrossM2
    : 0;
  const custoPorCaixa = consumo ? consumo.structureCost + consumo.wrapCost : 0;

  return (
    <section className="space-y-3">
      <header className="flex items-center gap-2">
        <Layers className="size-4 text-muted-foreground" />
        <h2 className="text-sm font-semibold">Peças do projeto</h2>
        <span className="ml-auto text-xs text-muted-foreground">
          {parts.length} de {MAX_PECAS}
        </span>
      </header>

      <p className="text-xs text-muted-foreground">
        Meça cada chapa e cada folha do jeito que elas vão ser cortadas. A
        quantidade é <strong className="font-medium">por caixa</strong> — o lote
        multiplica depois.
      </p>

      <div className="space-y-3">
        {parts.map((part, indice) => (
          <PartCard
            key={part.id}
            part={part}
            ordem={indice + 1}
            materials={materials}
            currency={currency}
            removivel={parts.length > 1}
          />
        ))}
      </div>

      <Button
        type="button"
        variant="outline"
        size="sm"
        className="w-full gap-1.5"
        onClick={() => addCustomPart("structure")}
        disabled={parts.length >= MAX_PECAS}
      >
        <Plus className="size-3.5" />
        Adicionar peça
      </Button>

      {consumo && (
        <div className="flex items-center justify-between rounded-md bg-muted/50 px-3 py-2 text-xs">
          <span className="text-muted-foreground">Consumo por caixa</span>
          <span className="font-mono tabular-nums">
            {areaPorCaixa.toFixed(4)} m² · {formatCurrency(custoPorCaixa, currency, 4)}
          </span>
        </div>
      )}
    </section>
  );
}

/** Rótulos dos dois papéis aceitos. Ferragem e berço entram pela lista de materiais. */
const PAPEIS: Array<{ value: ComponentRole; label: string }> = [
  { value: "structure", label: "Estrutura" },
  { value: "wrap", label: "Revestimento" },
];

function PartCard({
  part,
  ordem,
  materials,
  currency,
  removivel,
}: {
  part: CustomPartInput;
  ordem: number;
  materials: Material[];
  currency: string;
  removivel: boolean;
}) {
  const { updateCustomPart, removeCustomPart } = useQuoteStore(
    useShallow((s) => ({
      updateCustomPart: s.updateCustomPart,
      removeCustomPart: s.removeCustomPart,
    })),
  );

  const elegiveis = materiaisParaPeca(materials, part.role);

  /*
   * Custo desta peça pela mesma função que precifica o orçamento. Vale zero
   * enquanto a medida está vazia — e mostrar zero aí é correto: nada foi medido
   * ainda.
   */
  const consumo =
    part.width_mm > 0 && part.length_mm > 0 && part.quantity > 0
      ? customPartsConsumption([part])
      : null;

  const custo = consumo ? consumo.structureCost + consumo.wrapCost : 0;
  const areaBruta = consumo ? consumo.structureGrossM2 + consumo.wrapGrossM2 : 0;

  return (
    <div className="space-y-3 rounded-lg border p-3">
      <div className="flex items-center gap-2">
        <span className="font-mono text-xs text-muted-foreground">#{ordem}</span>

        <Input
          aria-label={`Nome da peça ${ordem}`}
          value={part.name}
          onChange={(e) => updateCustomPart(part.id, { name: e.target.value })}
          placeholder="Nome da peça"
          maxLength={120}
          className="h-8 border-transparent bg-transparent px-1 text-sm font-medium shadow-none focus-visible:border-input focus-visible:bg-background"
        />

        {/* A última peça não se apaga: lista vazia derruba o cálculo nas duas
            pontas. Quem quer sair do modelo livre troca de modelo. */}
        {removivel && (
          <Button
            type="button"
            variant="ghost"
            size="icon"
            aria-label={`Remover a peça ${ordem}`}
            onClick={() => removeCustomPart(part.id)}
            className="size-7 shrink-0 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
          >
            <Trash2 className="size-3.5" />
          </Button>
        )}
      </div>

      <div className="grid grid-cols-2 gap-2">
        <Field label="Papel" htmlFor={`part-${ordem}-role`}>
          <Select
            value={part.role}
            onValueChange={(value) =>
              updateCustomPart(part.id, { role: value as ComponentRole })
            }
          >
            {/* w-full porque o gatilho do shadcn nasce `w-fit`: com um nome de
                material longo ele crescia além da coluna e passava por cima do
                campo vizinho. */}
            <SelectTrigger id={`part-${ordem}-role`} className="h-8 w-full text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {PAPEIS.map((papel) => (
                <SelectItem key={papel.value} value={papel.value}>
                  {papel.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </Field>

        <Field label="Material" htmlFor={`part-${ordem}-material`}>
          <Select
            value={String(part.material_id)}
            onValueChange={(value) =>
              updateCustomPart(part.id, { material_id: Number(value) })
            }
          >
            {/* `title` porque o nome do material é o texto mais longo do cartão
                e será cortado numa coluna estreita — "Papelão ondulado B (3mm)"
                e "Papelão ondulado B (5mm)" só se distinguem no fim. */}
            <SelectTrigger
              id={`part-${ordem}-material`}
              title={elegiveis.find((m) => m.id === part.material_id)?.name}
              className="h-8 w-full text-xs"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {elegiveis.map((material) => (
                <SelectItem key={material.id} value={String(material.id)}>
                  {material.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </Field>
      </div>

      {/* items-end: o rótulo de uma coluna pode quebrar em duas linhas, e sem
          isso os campos ficariam em alturas diferentes. */}
      <div className="grid grid-cols-3 items-end gap-2">
        <NumberField
          id={`part-${ordem}-width`}
          label="Largura"
          suffix="mm"
          value={part.width_mm}
          onChange={(width_mm) => updateCustomPart(part.id, { width_mm })}
        />
        <NumberField
          id={`part-${ordem}-length`}
          label="Comprimento"
          suffix="mm"
          value={part.length_mm}
          onChange={(length_mm) => updateCustomPart(part.id, { length_mm })}
        />
        <NumberField
          id={`part-${ordem}-quantity`}
          label="Qtd."
          suffix="caixa"
          value={part.quantity}
          onChange={(quantity) => updateCustomPart(part.id, { quantity })}
        />
      </div>

      {/* A conta desta linha, aberta: área já com a perda do material e o que
          ela custa em cada caixa. É o número que justifica a peça existir. */}
      <p className="text-[11px] text-muted-foreground">
        <span className="font-mono tabular-nums">{areaBruta.toFixed(4)} m²</span> com{" "}
        <span className="font-mono tabular-nums">{part.waste_percent}%</span> de perda ·{" "}
        <span className="font-mono tabular-nums">
          {formatCurrency(custo, currency, 4)}
        </span>{" "}
        por caixa
      </p>
    </div>
  );
}

/**
 * `min-w-0` no invólucro, e não é detalhe.
 *
 * Item de grid nasce com `min-width: auto`, ou seja, se recusa a encolher
 * abaixo do próprio conteúdo. Com um `<Select>` de texto longo dentro, a célula
 * estourava a faixa e o campo passava por cima do vizinho — que é como
 * "Modelo" e "Quantidade" acabaram um dentro do outro.
 */
function Field({
  label,
  htmlFor,
  children,
}: {
  label: string;
  htmlFor: string;
  children: React.ReactNode;
}) {
  return (
    <div className="min-w-0 space-y-1">
      <Label
        htmlFor={htmlFor}
        className="block text-[10px] uppercase tracking-wider text-muted-foreground"
      >
        {label}
      </Label>
      {children}
    </div>
  );
}

/**
 * Campo numérico da peça.
 *
 * `value || ""` pelo mesmo motivo do formulário de dimensões: sem isso, apagar
 * o campo mostra "0" e obriga o usuário a selecionar e sobrescrever. O zero
 * chega ao estado e a store segura a requisição até virar número de verdade.
 */
function NumberField({
  id,
  label,
  suffix,
  value,
  onChange,
}: {
  id: string;
  label: string;
  suffix: string;
  value: number;
  onChange: (value: number) => void;
}) {
  return (
    <div className="min-w-0 space-y-1">
      {/*
        Rótulo e unidade num ÚNICO nó de texto.
        O <Label> do shadcn é `display:flex`, então rótulo e unidade viravam
        dois itens flexíveis lado a lado — que não quebram linha. Em três
        colunas de ~95px, "COMPRIMENTO (mm)" transbordava por cima de "QTD.".
        Como texto corrido, a unidade desce para a segunda linha quando falta
        espaço, e o `items-end` da grade mantém os campos alinhados.
      */}
      <Label
        htmlFor={id}
        className="block text-[10px] uppercase leading-tight tracking-wide text-muted-foreground"
      >
        {label} ({suffix})
      </Label>
      <Input
        id={id}
        type="number"
        inputMode="numeric"
        min={1}
        value={value || ""}
        onChange={(e) => onChange(Number(e.target.value) || 0)}
        className="h-8 font-mono text-xs tabular-nums"
      />
    </div>
  );
}

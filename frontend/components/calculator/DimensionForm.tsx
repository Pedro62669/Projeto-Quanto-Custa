"use client";

import { useShallow } from "zustand/react/shallow";
import { Box, Layers, Percent, Timer } from "lucide-react";

import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Slider } from "@/components/ui/slider";
import { Separator } from "@/components/ui/separator";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";

import { useQuoteStore } from "@/store/useQuoteStore";
import type { BoxModel, PricingMode } from "@/lib/pricing/types";

const BOX_MODELS: Array<{ value: BoxModel; label: string }> = [
  { value: "rsc", label: "Caixa americana (RSC)" },
  { value: "tray", label: "Caixa com tampa" },
  { value: "sleeve", label: "Luva / cinta" },
  { value: "pouch", label: "Saco / envelope" },
];

/**
 * Formulário de especificação.
 *
 * Hierarquia deliberada: dimensões e material ficam sempre visíveis porque são
 * o que o usuário mexe o tempo todo; desperdício, tempo e modo de precificação
 * ficam num accordion fechado — são ajustes finos que, expostos de saída,
 * transformariam a tela numa planilha e afogariam o que importa.
 */
export function DimensionForm() {
  const { spec, materials, updateSpec, selectMaterial } = useQuoteStore(
    useShallow((s) => ({
      spec: s.spec,
      materials: s.materials,
      updateSpec: s.updateSpec,
      selectMaterial: s.selectMaterial,
    })),
  );

  return (
    <div className="space-y-6">
      {/* ── Dimensões ──────────────────────────────────────────────────── */}
      <section className="space-y-3">
        <header className="flex items-center gap-2">
          <Box className="size-4 text-muted-foreground" />
          <h2 className="text-sm font-semibold">Dimensões internas</h2>
          <span className="ml-auto text-xs text-muted-foreground">mm</span>
        </header>

        <div className="grid grid-cols-3 gap-3">
          <DimensionInput
            id="width"
            label="Largura"
            value={spec.width_mm}
            onChange={(width_mm) => updateSpec({ width_mm })}
          />
          <DimensionInput
            id="height"
            label="Altura"
            value={spec.height_mm}
            onChange={(height_mm) => updateSpec({ height_mm })}
          />
          <DimensionInput
            id="depth"
            label="Profundidade"
            value={spec.depth_mm}
            onChange={(depth_mm) => updateSpec({ depth_mm })}
          />
        </div>
      </section>

      {/* ── Material e modelo ──────────────────────────────────────────── */}
      <section className="space-y-3">
        <header className="flex items-center gap-2">
          <Layers className="size-4 text-muted-foreground" />
          <h2 className="text-sm font-semibold">Material e modelo</h2>
        </header>

        <div className="space-y-2">
          <Label htmlFor="material">Matéria-prima</Label>
          <Select
            value={spec.material_id?.toString() ?? ""}
            onValueChange={(value) => selectMaterial(Number(value))}
          >
            <SelectTrigger id="material">
              <SelectValue placeholder="Selecione o material" />
            </SelectTrigger>
            <SelectContent>
              {materials.map((material) => (
                <SelectItem key={material.id} value={material.id.toString()}>
                  <span className="flex items-center gap-2">
                    {/* Mesma cor aplicada ao modelo 3D: cria o vínculo visual
                        entre o que foi escolhido e o que está renderizado. */}
                    <span
                      className="size-3 shrink-0 rounded-sm border"
                      style={{ backgroundColor: material.color_hex }}
                      aria-hidden
                    />
                    {material.name}
                    <span className="text-xs text-muted-foreground">
                      · {material.type_label}
                    </span>
                  </span>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-2">
            <Label htmlFor="box-model">Modelo</Label>
            <Select
              value={spec.box_model}
              onValueChange={(value) => updateSpec({ box_model: value as BoxModel })}
            >
              <SelectTrigger id="box-model">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {BOX_MODELS.map((model) => (
                  <SelectItem key={model.value} value={model.value}>
                    {model.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label htmlFor="quantity">Quantidade</Label>
            <Input
              id="quantity"
              type="number"
              inputMode="numeric"
              min={1}
              value={spec.quantity}
              onChange={(e) => updateSpec({ quantity: Math.max(1, Number(e.target.value) || 1) })}
            />
          </div>
        </div>
      </section>

      <Separator />

      {/* ── Margem de lucro (sempre visível: é a alavanca principal) ────── */}
      <section className="space-y-3">
        <header className="flex items-center justify-between">
          <span className="flex items-center gap-2">
            <Percent className="size-4 text-muted-foreground" />
            <h2 className="text-sm font-semibold">Lucro desejado</h2>
          </span>
          <span className="font-mono text-sm font-semibold tabular-nums">
            {spec.profit_margin_percent}%
          </span>
        </header>

        <Slider
          value={[spec.profit_margin_percent]}
          onValueChange={([profit_margin_percent]) => updateSpec({ profit_margin_percent })}
          min={0}
          // Teto de 99% no modo "margin": a fórmula é custo ÷ (1 − m) e em
          // 100% o preço tenderia ao infinito.
          max={spec.pricing_mode === "margin" ? 99 : 300}
          step={1}
          aria-label="Percentual de lucro"
        />

        <ToggleGroup
          type="single"
          size="sm"
          variant="outline"
          value={spec.pricing_mode}
          onValueChange={(value) =>
            value && updateSpec({ pricing_mode: value as PricingMode })
          }
          className="w-full"
        >
          <ToggleGroupItem value="markup" className="flex-1 text-xs">
            Sobre o custo
          </ToggleGroupItem>
          <ToggleGroupItem value="margin" className="flex-1 text-xs">
            Sobre a venda
          </ToggleGroupItem>
        </ToggleGroup>

        <p className="text-xs text-muted-foreground">
          {spec.pricing_mode === "markup"
            ? "Acrescenta a porcentagem sobre o custo. A margem real sobre a venda fica menor."
            : "Garante essa margem líquida sobre o preço final de venda."}
        </p>
      </section>

      {/* ── Ajustes avançados ──────────────────────────────────────────── */}
      <Accordion type="single" collapsible>
        <AccordionItem value="advanced" className="border-b-0">
          <AccordionTrigger className="py-2 text-sm">
            <span className="flex items-center gap-2">
              <Timer className="size-4 text-muted-foreground" />
              Ajustes de produção
            </span>
          </AccordionTrigger>

          <AccordionContent className="space-y-4 pt-2">
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label htmlFor="waste">Desperdício / perda</Label>
                <span className="font-mono text-xs tabular-nums text-muted-foreground">
                  {spec.waste_percent}%
                </span>
              </div>
              <Slider
                id="waste"
                value={[spec.waste_percent]}
                onValueChange={([waste_percent]) => updateSpec({ waste_percent })}
                min={0}
                max={50}
                step={0.5}
              />
              <p className="text-xs text-muted-foreground">
                Aparas, refile e perdas de acerto de máquina.
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="minutes">Tempo de produção por unidade (min)</Label>
              <Input
                id="minutes"
                type="number"
                inputMode="decimal"
                min={0}
                step={0.1}
                value={spec.production_minutes_per_unit}
                onChange={(e) =>
                  updateSpec({
                    production_minutes_per_unit: Math.max(0, Number(e.target.value) || 0),
                  })
                }
              />
              <p className="text-xs text-muted-foreground">
                Base do cálculo de mão de obra, hora-máquina e energia.
              </p>
            </div>
          </AccordionContent>
        </AccordionItem>
      </Accordion>
    </div>
  );
}

/**
 * Input de dimensão.
 *
 * `value || ""` em vez de `value`: sem isso, apagar o campo mostraria "0" e o
 * usuário teria de selecionar e sobrescrever. O store recebe 0 e o motor trata
 * — a UI apenas não obriga ninguém a lutar contra um zero teimoso.
 */
function DimensionInput({
  id,
  label,
  value,
  onChange,
}: {
  id: string;
  label: string;
  value: number;
  onChange: (value: number) => void;
}) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id} className="text-xs text-muted-foreground">
        {label}
      </Label>
      <Input
        id={id}
        type="number"
        inputMode="numeric"
        min={10}
        max={3000}
        value={value || ""}
        onChange={(e) => onChange(Number(e.target.value) || 0)}
        className="font-mono tabular-nums"
      />
    </div>
  );
}

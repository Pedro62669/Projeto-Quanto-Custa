"use client";

import { useShallow } from "zustand/react/shallow";
import { Box, Layers, PackageOpen, Percent, RotateCcw, Timer } from "lucide-react";

import { Button } from "@/components/ui/button";
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

import { materiaisParaPeca, useQuoteStore } from "@/store/useQuoteStore";
import { CustomPartsEditor } from "@/components/calculator/CustomPartsEditor";
import { ListaDeMateriais } from "@/components/calculator/ListaDeMateriais";
import {
  defaultLidDimensions,
  hasSeparateLid,
  isCylindrical,
  isFree,
} from "@/lib/pricing/engine";
import type { BoxModel, PricingMode } from "@/lib/pricing/types";

/*
 * Os modelos vêm do SERVIDOR, e não de uma lista aqui.
 *
 * Havia uma escrita à mão neste arquivo com OITO dos catorze do enum: faltava a
 * família rígida inteira — tampa solta, livro, livro com aba e as três caixas
 * ímã. O motor as precificava, o 3D as desenhava, a API as devolvia em
 * `pricing/parameters`, e não havia como escolhê-las na tela.
 *
 * A causa era a cópia. Cada modelo novo exigia lembrar de um arquivo que não
 * tinha por que ser lembrado, e o rótulo vivia em dois lugares. Agora existe um
 * só: `BoxModel::label()`.
 */

/**
 * Formulário de especificação.
 *
 * Hierarquia deliberada: dimensões e material ficam sempre visíveis porque são
 * o que o usuário mexe o tempo todo; desperdício, tempo e modo de precificação
 * ficam num accordion fechado — são ajustes finos que, expostos de saída,
 * transformariam a tela numa planilha e afogariam o que importa.
 */
export function DimensionForm() {
  const { spec, materials, boxModels, updateSpec, selectMaterial, selectBoxModel } = useQuoteStore(
    useShallow((s) => ({
      spec: s.spec,
      materials: s.materials,
      boxModels: s.boxModels,
      updateSpec: s.updateSpec,
      selectMaterial: s.selectMaterial,
      selectBoxModel: s.selectBoxModel,
    })),
  );

  const cilindrico = isCylindrical(spec.box_model);
  const modeloLivre = isFree(spec.box_model);

  return (
    <div className="space-y-6">
      {/* ── Dimensões, ou as peças ─────────────────────────────────────── */}
      {modeloLivre ? (
        <CustomPartsEditor />
      ) : (
        <section className="space-y-3">
          <header className="flex items-center gap-2">
            <Box className="size-4 text-muted-foreground" />
            <h2 className="text-sm font-semibold">Dimensões internas</h2>
            <span className="ml-auto text-xs text-muted-foreground">mm</span>
          </header>

          {/*
            Um cilindro não tem largura e profundidade independentes: tem
            diâmetro. Mostrar três campos pediria ao usuário um dado que não
            existe — e que o motor ignoraria em silêncio.
          */}
          <div
            className={
              cilindrico ? "grid grid-cols-2 gap-3" : "grid grid-cols-3 gap-3"
            }
          >
            <DimensionInput
              id="width"
              label={cilindrico ? "Diâmetro" : "Largura"}
              value={spec.width_mm}
              onChange={(width_mm) =>
                updateSpec(
                  // Profundidade acompanha o diâmetro: para um cilindro, ambas
                  // SÃO a mesma medida (é a caixa envolvente da peça).
                  cilindrico ? { width_mm, depth_mm: width_mm } : { width_mm },
                )
              }
            />
            <DimensionInput
              id="height"
              label="Altura"
              value={spec.height_mm}
              onChange={(height_mm) => updateSpec({ height_mm })}
            />
            {!cilindrico && (
              <DimensionInput
                id="depth"
                label="Profundidade"
                value={spec.depth_mm}
                onChange={(depth_mm) => updateSpec({ depth_mm })}
              />
            )}
          </div>
        </section>
      )}

      {/* ── Material e modelo ──────────────────────────────────────────── */}
      <section className="space-y-3">
        <header className="flex items-center gap-2">
          <Layers className="size-4 text-muted-foreground" />
          <h2 className="text-sm font-semibold">Material e modelo</h2>
        </header>

        <div className="space-y-2">
          <Label htmlFor="material">
            {modeloLivre ? "Matéria-prima de referência" : "Matéria-prima"}
          </Label>
          <Select
            value={spec.material_id?.toString() ?? ""}
            onValueChange={(value) => selectMaterial(Number(value))}
          >
            {/* w-full: o gatilho do shadcn nasce `w-fit` e cresce com o texto.
                Num campo de largura livre isso só deixava a caixa desalinhada;
                dentro de uma grade, passava por cima do campo ao lado. */}
            <SelectTrigger id="material" className="w-full">
              <SelectValue placeholder="Selecione o material" />
            </SelectTrigger>
            <SelectContent>
              {/*
                Só material medido em ÁREA.

                A estrutura é cortada em milímetros, e ímã não se corta: o motor
                recusa convertê-lo para R$/m² e devolve 422. A lista vinha
                inteira, então bastava a empresa cadastrar uma ferragem para o
                seletor oferecer a peça que derruba o cálculo — e ela aparecia
                em primeiro se o nome viesse antes no alfabeto, porque a API
                ordena por nome e a calculadora abre no primeiro item.

                Ferragem e berço entram pela lista de materiais, logo abaixo.
              */}
              {materiaisParaPeca(materials, "structure").map((material) => (
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

          {/* No modelo livre o custo vem do material DE CADA PEÇA. Este campo
              continua sendo gravado no orçamento, e dizer o que ele faz aqui
              evita que alguém o troque esperando ver o preço mudar. */}
          {modeloLivre && (
            <p className="text-xs text-muted-foreground">
              No modelo livre o preço sai do material de cada peça. Este fica
              registrado no orçamento como a matéria-prima principal do projeto.
            </p>
          )}
        </div>

        {/*
          `min-w-0` nas duas células: item de grid se recusa a encolher abaixo
          do próprio conteúdo, e "Modelo livre (peças medidas)" é largo o
          bastante para empurrar a quantidade para debaixo de si.
        */}
        <div className="grid grid-cols-2 gap-3">
          <div className="min-w-0 space-y-2">
            <Label htmlFor="box-model">Modelo</Label>
            <Select
              value={spec.box_model}
              onValueChange={(value) => selectBoxModel(value as BoxModel)}
            >
              <SelectTrigger id="box-model" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {boxModels.map((model) => (
                  <SelectItem key={model.value} value={model.value}>
                    {model.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="min-w-0 space-y-2">
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

      {/* ── Tampa: bandeja e tubo têm peça separada ─────────────────────── */}
      {hasSeparateLid(spec.box_model) && <LidFields />}

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

      {/* ── A lista de materiais ───────────────────────────────────────────
          Depois da matéria-prima, que é a estrutura, e antes dos ajustes de
          produção: a ordem em que a caixa é pensada — do que ela é feita antes
          de quanto tempo leva para montar. */}
      <ListaDeMateriais />

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
            {modeloLivre ? (
              /*
               * Duas coisas mudam de lugar no modelo livre.
               *
               * A perda sai: ela é do MATERIAL de cada peça, e um percentual
               * único aqui não tem onde ser aplicado — o motor o ignora. Deixar
               * o controle na tela seria oferecer uma alavanca desligada.
               *
               * As medidas externas entram: continuam sendo gravadas no
               * orçamento e a API as exige, então escondê-las mandaria ao
               * servidor um número que ninguém viu. Aqui elas aparecem pelo que
               * são — referência, não base de cálculo.
               */
              <div className="space-y-3">
                <div>
                  <Label className="text-sm">Medidas externas de referência</Label>
                  <p className="text-xs text-muted-foreground">
                    Não entram no preço: quem o define são as peças. Servem para
                    identificar a caixa na proposta e na ficha de produção.
                  </p>
                </div>

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

                <p className="text-xs text-muted-foreground">
                  A perda de cada peça vem do material dela — papelão, kraft e
                  tecido desperdiçam percentuais diferentes, e cada linha aplica
                  o seu.
                </p>
              </div>
            ) : (
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
            )}

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
 * Medidas da tampa.
 *
 * Os campos nascem preenchidos com a sugestão calculada a partir da base e
 * ficam em modo AUTOMÁTICO: mexer na caixa move a tampa junto. Digitar em um
 * campo o fixa — e só ele; dá para travar a altura da tampa e deixar largura
 * e profundidade acompanhando a base.
 *
 * Esse é o ponto do desenho: o valor sugerido é um bom palpite, não uma
 * imposição, e o usuário não precisa recalcular nada para começar.
 */
function LidFields() {
  const { spec, material, updateSpec } = useQuoteStore(
    useShallow((s) => ({
      spec: s.spec,
      material: s.materials.find((m) => m.id === s.spec.material_id) ?? null,
      updateSpec: s.updateSpec,
    })),
  );

  // Mesma função usada pelo motor de preço e pelo 3D — sem constante repetida.
  const sugerida = defaultLidDimensions(
    spec.box_model,
    spec.width_mm,
    spec.height_mm,
    spec.depth_mm,
    material?.thickness_mm ?? 0,
  );

  if (!sugerida) return null;

  const cilindrico = isCylindrical(spec.box_model);

  const manual =
    spec.lid_width_mm !== null ||
    spec.lid_depth_mm !== null ||
    spec.lid_height_mm !== null;

  const arredonda = (v: number) => Math.round(v * 10) / 10;

  return (
    <section className="space-y-3 rounded-lg border border-dashed p-3">
      <header className="flex items-center gap-2">
        <PackageOpen className="size-4 text-muted-foreground" />
        <h2 className="text-sm font-semibold">Tampa</h2>

        {manual ? (
          <Button
            variant="ghost"
            size="sm"
            className="ml-auto h-6 gap-1 px-2 text-xs font-normal text-muted-foreground"
            onClick={() =>
              updateSpec({
                lid_width_mm: null,
                lid_depth_mm: null,
                lid_height_mm: null,
              })
            }
          >
            <RotateCcw className="size-3" />
            Automático
          </Button>
        ) : (
          <span className="ml-auto text-xs text-muted-foreground">
            automático · acompanha a caixa
          </span>
        )}
      </header>

      <div
        className={cilindrico ? "grid grid-cols-2 gap-3" : "grid grid-cols-3 gap-3"}
      >
        <DimensionInput
          id="lid-width"
          label={cilindrico ? "Diâmetro" : "Largura"}
          value={arredonda(spec.lid_width_mm ?? sugerida.widthMm)}
          onChange={(lid_width_mm) =>
            // Tampa de cilindro é circular: os dois eixos andam juntos, senão
            // ela sairia oval e não encaixaria no corpo redondo.
            updateSpec(
              cilindrico
                ? { lid_width_mm, lid_depth_mm: lid_width_mm }
                : { lid_width_mm },
            )
          }
          min={spec.width_mm}
        />
        {!cilindrico && (
          <DimensionInput
            id="lid-depth"
            label="Profundidade"
            value={arredonda(spec.lid_depth_mm ?? sugerida.depthMm)}
            onChange={(lid_depth_mm) => updateSpec({ lid_depth_mm })}
            min={spec.depth_mm}
          />
        )}
        <DimensionInput
          id="lid-height"
          label="Altura"
          value={arredonda(spec.lid_height_mm ?? sugerida.heightMm)}
          onChange={(lid_height_mm) => updateSpec({ lid_height_mm })}
          min={1}
        />
      </div>

      <p className="text-xs text-muted-foreground">
        {cilindrico
          ? "A tampa encaixa por fora: o diâmetro não pode ser menor que o do tubo."
          : "A tampa encaixa por fora: largura e profundidade não podem ser menores que as da caixa."}
      </p>
    </section>
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
  min = 10,
}: {
  id: string;
  label: string;
  value: number;
  onChange: (value: number) => void;
  min?: number;
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
        min={min}
        max={3200}
        value={value || ""}
        onChange={(e) => onChange(Number(e.target.value) || 0)}
        className="font-mono tabular-nums"
      />
    </div>
  );
}

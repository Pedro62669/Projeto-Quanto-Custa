"use client";

import { useState } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Field, FormError, NumberField, TextField } from "@/components/form/Field";
import type { FieldErrors } from "@/components/form/Field";

import {
  api,
  ApiError,
  type GrainDirectionValue,
  type MaterialAdmin,
  type MaterialPayload,
  type MaterialUnitValue,
} from "@/lib/api";
import { mensagemDeErro } from "@/hooks/useApi";
import { formatCurrency } from "@/lib/pricing/engine";
import { ROTULO_FIBRA, ROTULO_TIPO_MATERIAL, ROTULO_UNIDADE } from "@/lib/rotulos";

/** O que o formulário edita. Números vazios são `null`, nunca zero. */
interface Formulario {
  name: string;
  type: string;
  description: string;
  cost_unit: MaterialUnitValue;
  cost_per_unit: number | null;
  grammage_kg_per_m2: number | null;
  lot_quantity: number | null;
  lot_purchase_cost: number | null;
  lot_freight_cost: number | null;
  sheet_width_mm: number | null;
  sheet_length_mm: number | null;
  grain_direction: GrainDirectionValue;
  default_waste_percent: number | null;
  thickness_mm: number | null;
  color_hex: string;
  texture_url: string;
  is_active: boolean;
}

const VAZIO: Formulario = {
  name: "",
  type: "cardboard",
  description: "",
  cost_unit: "m2",
  cost_per_unit: null,
  grammage_kg_per_m2: null,
  lot_quantity: null,
  lot_purchase_cost: null,
  lot_freight_cost: null,
  sheet_width_mm: null,
  sheet_length_mm: null,
  grain_direction: "none",
  default_waste_percent: 10,
  thickness_mm: null,
  color_hex: "#C8A06A",
  texture_url: "",
  is_active: true,
};

function paraFormulario(material: MaterialAdmin): Formulario {
  return {
    name: material.name,
    type: material.type,
    description: material.description ?? "",
    cost_unit: material.cost_unit,
    cost_per_unit: material.cost_per_unit,
    grammage_kg_per_m2: material.grammage_kg_per_m2,
    lot_quantity: material.lot_quantity,
    lot_purchase_cost: material.lot_purchase_cost,
    lot_freight_cost: material.lot_freight_cost,
    sheet_width_mm: material.sheet_width_mm,
    sheet_length_mm: material.sheet_length_mm,
    grain_direction: material.grain_direction ?? "none",
    default_waste_percent: material.default_waste_percent,
    thickness_mm: material.thickness_mm,
    color_hex: material.color_hex,
    texture_url: material.texture_url ?? "",
    is_active: material.is_active,
  };
}

/**
 * Cadastro de material.
 *
 * O formulário mais denso do sistema, e o mais importante: é dele que sai o
 * número que multiplica a área de toda caixa. Organizado em quatro blocos na
 * ordem em que a informação existe no mundo — o que é, quanto custou, em que
 * formato chegou, e como aparece na tela.
 *
 * O CUSTO POR M² RESOLVIDO aparece ao vivo enquanto se digita. A precedência
 * (lote → R$/m² → R$/kg × gramatura) é invisível no formulário, e sem essa
 * linha o usuário cadastra o lote, não entende por que o preço mudou, e some
 * com a nota fiscal na mão.
 */
export function MaterialSheet({
  material,
  open,
  onOpenChange,
  onSaved,
}: {
  material: MaterialAdmin | null;
  open: boolean;
  onOpenChange: (aberto: boolean) => void;
  onSaved: () => void;
}) {
  const [form, setForm] = useState<Formulario>(
    material ? paraFormulario(material) : VAZIO,
  );
  const [errors, setErrors] = useState<FieldErrors>({});
  const [erroGeral, setErroGeral] = useState<string | null>(null);
  const [salvando, setSalvando] = useState(false);

  const define = <K extends keyof Formulario>(campo: K, valor: Formulario[K]) =>
    setForm((atual) => ({ ...atual, [campo]: valor }));

  async function salvar(evento: React.FormEvent) {
    evento.preventDefault();

    setSalvando(true);
    setErrors({});
    setErroGeral(null);

    // String vazia vira null: o backend valida `nullable|url`, e "" não é URL.
    const payload: MaterialPayload = {
      ...form,
      description: form.description || null,
      texture_url: form.texture_url || null,
    };

    try {
      if (material) {
        await api.materials.update(material.id, payload);
      } else {
        await api.materials.create(payload);
      }

      toast.success(material ? "Material atualizado" : "Material cadastrado", {
        description: form.name,
      });

      onSaved();
      onOpenChange(false);
    } catch (erro) {
      if (erro instanceof ApiError && Object.keys(erro.errors).length > 0) {
        setErrors(erro.errors);
      } else {
        setErroGeral(mensagemDeErro(erro));
      }
    } finally {
      setSalvando(false);
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="sm:max-w-lg">
        <form onSubmit={salvar} className="flex min-h-0 flex-1 flex-col">
          <SheetHeader>
            <SheetTitle>{material ? "Editar material" : "Novo material"}</SheetTitle>
            <SheetDescription>
              O custo daqui multiplica a área de toda caixa que usar este insumo.
            </SheetDescription>
          </SheetHeader>

          <SheetBody className="space-y-6">
            <FormError message={erroGeral} />

            {/* ── O que é ────────────────────────────────────────────────── */}
            <section className="space-y-3">
              <Titulo>Identificação</Titulo>

              <TextField
                label="Nome"
                name="name"
                required
                value={form.name}
                onChange={(v) => define("name", v)}
                errors={errors}
                placeholder="Papelão cinza 1,9mm"
                maxLength={255}
              />

              <div className="grid grid-cols-2 gap-3">
                <Field label="Tipo" name="type" errors={errors} required>
                  {(props) => (
                    <Select
                      value={form.type}
                      onValueChange={(v) => define("type", v)}
                    >
                      <SelectTrigger {...props} className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {Object.entries(ROTULO_TIPO_MATERIAL).map(([valor, rotulo]) => (
                          <SelectItem key={valor} value={valor}>
                            {rotulo}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                </Field>

                <NumberField
                  label="Espessura"
                  suffix="mm"
                  name="thickness_mm"
                  value={form.thickness_mm}
                  onChange={(v) => define("thickness_mm", v)}
                  errors={errors}
                  min={0}
                  max={100}
                  hint="Vira a parede visível no 3D."
                />
              </div>

              <TextField
                label="Descrição"
                name="description"
                value={form.description}
                onChange={(v) => define("description", v)}
                errors={errors}
                maxLength={1000}
              />
            </section>

            {/* ── Quanto custou ──────────────────────────────────────────── */}
            <section className="space-y-3">
              <Titulo>Custo de compra</Titulo>

              <div className="grid grid-cols-2 gap-3">
                <Field label="Unidade" name="cost_unit" errors={errors} required>
                  {(props) => (
                    <Select
                      value={form.cost_unit}
                      onValueChange={(v) => define("cost_unit", v as MaterialUnitValue)}
                    >
                      <SelectTrigger {...props} className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {Object.entries(ROTULO_UNIDADE).map(([valor, rotulo]) => (
                          <SelectItem key={valor} value={valor}>
                            {rotulo}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                </Field>

                <NumberField
                  label="Preço por unidade"
                  name="cost_per_unit"
                  required
                  value={form.cost_per_unit}
                  onChange={(v) => define("cost_per_unit", v)}
                  errors={errors}
                  min={0}
                />
              </div>

              {/* A gramatura só existe para quem compra em quilo — é ela que
                  converte R$/kg em R$/m². Fora daí, é um campo sem sentido. */}
              {form.cost_unit === "kg" && (
                <NumberField
                  label="Gramatura"
                  suffix="kg/m²"
                  name="grammage_kg_per_m2"
                  required
                  value={form.grammage_kg_per_m2}
                  onChange={(v) => define("grammage_kg_per_m2", v)}
                  errors={errors}
                  min={0}
                  hint="Sem ela não há como converter o quilo em metro quadrado. Papelão de 300g/m² = 0,300."
                />
              )}

              <div className="grid grid-cols-3 gap-3">
                <NumberField
                  label="Folhas no lote"
                  name="lot_quantity"
                  value={form.lot_quantity}
                  onChange={(v) => define("lot_quantity", v)}
                  errors={errors}
                  min={1}
                />
                <NumberField
                  label="Valor do lote"
                  name="lot_purchase_cost"
                  value={form.lot_purchase_cost}
                  onChange={(v) => define("lot_purchase_cost", v)}
                  errors={errors}
                  min={0}
                />
                <NumberField
                  label="Frete"
                  name="lot_freight_cost"
                  value={form.lot_freight_cost}
                  onChange={(v) => define("lot_freight_cost", v)}
                  errors={errors}
                  min={0}
                />
              </div>

              <p className="text-xs text-muted-foreground">
                Preencher o lote com o frete faz o custo sair da NOTA, e não de um
                preço digitado meses atrás. Quando há lote, ele tem precedência
                sobre o preço por unidade acima.
              </p>

              <CustoResolvido form={form} />
            </section>

            {/* ── Em que formato chegou ──────────────────────────────────── */}
            <section className="space-y-3">
              <Titulo>Folha e fibra</Titulo>

              <div className="grid grid-cols-2 gap-3">
                <NumberField
                  label="Largura da folha"
                  suffix="mm"
                  name="sheet_width_mm"
                  value={form.sheet_width_mm}
                  onChange={(v) => define("sheet_width_mm", v)}
                  errors={errors}
                  min={1}
                />
                <NumberField
                  label="Comprimento da folha"
                  suffix="mm"
                  name="sheet_length_mm"
                  value={form.sheet_length_mm}
                  onChange={(v) => define("sheet_length_mm", v)}
                  errors={errors}
                  min={1}
                />
              </div>

              <Field
                label="Sentido da fibra"
                name="grain_direction"
                errors={errors}
                hint="Papel cortado atravessado empena depois de colado — e o defeito aparece dias depois, com a caixa já entregue."
              >
                {(props) => (
                  <Select
                    value={form.grain_direction}
                    onValueChange={(v) => define("grain_direction", v as GrainDirectionValue)}
                  >
                    <SelectTrigger {...props} className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {Object.entries(ROTULO_FIBRA).map(([valor, rotulo]) => (
                        <SelectItem key={valor} value={valor}>
                          {rotulo}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              </Field>

              <p className="text-xs text-muted-foreground">
                A medida da folha é o que permite o plano de corte da ficha técnica
                mostrar a perda REAL, ao lado da perda orçada abaixo.
              </p>
            </section>

            {/* ── Como aparece ───────────────────────────────────────────── */}
            <section className="space-y-3">
              <Titulo>Perda e aparência</Titulo>

              <NumberField
                label="Perda padrão"
                suffix="%"
                name="default_waste_percent"
                value={form.default_waste_percent}
                onChange={(v) => define("default_waste_percent", v)}
                errors={errors}
                min={0}
                max={90}
                hint="Aparas, refile e acerto de máquina. É o número que entra no preço; o plano de corte mostra quanto ele erra."
              />

              <div className="grid grid-cols-2 gap-3">
                <Field label="Cor no 3D" name="color_hex" errors={errors}>
                  {(props) => (
                    <div className="flex items-center gap-2">
                      <Input
                        {...props}
                        type="color"
                        value={form.color_hex}
                        onChange={(e) => define("color_hex", e.target.value)}
                        className="h-8 w-12 p-1"
                      />
                      <Input
                        value={form.color_hex}
                        onChange={(e) => define("color_hex", e.target.value)}
                        className="font-mono text-xs"
                        maxLength={7}
                      />
                    </div>
                  )}
                </Field>

                <TextField
                  label="Textura (URL)"
                  name="texture_url"
                  value={form.texture_url}
                  onChange={(v) => define("texture_url", v)}
                  errors={errors}
                  placeholder="https://…"
                />
              </div>

              <div className="flex items-center justify-between rounded-lg border p-3">
                <div>
                  <Label htmlFor="material-ativo" className="text-sm">
                    Disponível para orçamento
                  </Label>
                  <p className="text-xs text-muted-foreground">
                    Desativado, some da calculadora sem afetar orçamentos já gravados.
                  </p>
                </div>
                <Switch
                  id="material-ativo"
                  checked={form.is_active}
                  onCheckedChange={(v) => define("is_active", v)}
                />
              </div>
            </section>
          </SheetBody>

          <SheetFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={salvando}
            >
              Cancelar
            </Button>
            <Button type="submit" disabled={salvando}>
              {salvando && <Loader2 className="size-4 animate-spin" />}
              {material ? "Salvar alterações" : "Cadastrar material"}
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  );
}

function Titulo({ children }: { children: React.ReactNode }) {
  return (
    <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
      {children}
    </h3>
  );
}

/**
 * O custo por m² que o motor vai usar, calculado enquanto se digita.
 *
 * ⚠️ Espelha `Material::costPerSquareMeter()`. A duplicação é consciente e
 * limitada a esta prévia: o valor que PRECIFICA continua vindo do servidor, em
 * `cost_per_m2`. Aqui ela existe para o usuário ver, no momento da digitação,
 * qual dos três caminhos o cadastro dele acabou tomando.
 */
function CustoResolvido({ form }: { form: Formulario }) {
  const resolvido = resolveCustoPorM2(form);

  if (resolvido === null) {
    return (
      <p className="rounded-md bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
        {form.cost_unit === "kg"
          ? "Informe a gramatura para converter o quilo em metro quadrado."
          : "Este material é contado ou comprado em bloco — não tem custo por m² e não entra como peça medida."}
      </p>
    );
  }

  return (
    <div className="rounded-md bg-muted/50 px-3 py-2 text-xs">
      <span className="text-muted-foreground">Custo que entra no cálculo: </span>
      <span className="font-mono font-semibold tabular-nums">
        {formatCurrency(resolvido.valor, "BRL", 4)}/m²
      </span>
      <span className="text-muted-foreground"> · {resolvido.origem}</span>
    </div>
  );
}

function resolveCustoPorM2(
  form: Formulario,
): { valor: number; origem: string } | null {
  const areaDaFolha =
    form.sheet_width_mm && form.sheet_length_mm
      ? (form.sheet_width_mm * form.sheet_length_mm) / 1_000_000
      : null;

  // 1º) O lote, quando completo: é o número da nota fiscal, com o frete rateado.
  if (form.lot_purchase_cost !== null && form.lot_quantity && form.lot_quantity >= 1) {
    const porFolha =
      (form.lot_purchase_cost + (form.lot_freight_cost ?? 0)) / form.lot_quantity;

    if (areaDaFolha && areaDaFolha > 0) {
      return {
        valor: porFolha / areaDaFolha,
        origem: `lote de ${form.lot_quantity} folhas com frete`,
      };
    }
  }

  if (form.cost_unit === "m2" && form.cost_per_unit !== null) {
    return { valor: form.cost_per_unit, origem: "preço por m² informado" };
  }

  if (form.cost_unit === "kg" && form.cost_per_unit !== null && form.grammage_kg_per_m2) {
    return {
      valor: form.cost_per_unit * form.grammage_kg_per_m2,
      origem: `${form.cost_per_unit}/kg × ${form.grammage_kg_per_m2} kg/m²`,
    };
  }

  return null;
}

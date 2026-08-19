"use client";

import { useShallow } from "zustand/react/shallow";
import { Boxes, Plus, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

import {
  materiaisParaPapel,
  selectComponents,
  useQuoteStore,
} from "@/store/useQuoteStore";
import { formatCurrency, isMagnet, isRigid } from "@/lib/pricing/engine";
import type { ComponentInput, CradleType, Material } from "@/lib/pricing/types";

/**
 * A lista de materiais da cartonagem rígida.
 *
 * Uma caixa rígida não é feita de um material só: papelão cinza dá estrutura,
 * papel de revestimento cobre por fora e vira para dentro, ímãs fecham a tampa
 * e um berço acomoda o produto. O motor precifica os quatro papéis desde a Fase
 * 3, a API os aceita e a ficha técnica os lista — e não havia campo para
 * nenhum deles. A caixa rígida só podia ser orçada como se fosse papelão nu.
 *
 * A estrutura NÃO aparece aqui: ela é o material principal do orçamento,
 * escolhido no formulário de dimensões. Repeti-la nesta lista daria dois
 * lugares para mudar a mesma coisa.
 *
 * Revestimento e berço são únicos por peça; ferragem é lista. É por isso que
 * "Adicionar revestimento" SUBSTITUI e "Adicionar ferragem" acumula — o
 * servidor já trata o segundo revestimento como troca de seleção, e duas linhas
 * na tela mostrariam dois custos onde um só seria cobrado.
 */
export function ListaDeMateriais() {
  const { componentes, materiais, currency, boxModel, addComponent } = useQuoteStore(
    useShallow((s) => ({
      // Seletor com identidade estável: um `?? []` aqui devolveria um array novo
      // a cada chamada e o useShallow re-renderizaria para sempre.
      componentes: selectComponents(s),
      materiais: s.materials,
      currency: s.currency,
      boxModel: s.spec.box_model,
      addComponent: s.addComponent,
    })),
  );

  /*
   * O que a caixa comporta depende do MODELO, e não só do que há no cadastro.
   *
   * REVESTIMENTO é de cartonagem rígida: papelão cinza coberto com papel. Uma
   * caixa americana é chapa vincada que se sustenta sozinha, e não tem o que
   * revestir — a regra já estava escrita no enum BoxModel, no docblock de
   * `isRigid()`, e a tela não a respeitava.
   *
   * FERRAGEM é da família ímã. O ímã fica embutido entre o cinza e o papel da
   * aba frontal; sem essa aba não há onde alojá-lo. Oferecer ferragem numa
   * caixa de envio convida a lançar um custo que a peça não tem, e o preço sai
   * plausível — que é o pior tipo de erro.
   *
   * No modelo livre o revestimento já é uma PEÇA medida à mão, com papel `wrap`
   * na lista de retângulos. Oferecê-lo aqui também criaria dois caminhos para o
   * mesmo custo, e o motor somaria os dois.
   */

  const podeRevestir =
    isRigid(boxModel) && materiaisParaPapel(materiais, "wrap").length > 0;

  const podeFerragem =
    isMagnet(boxModel) && materiaisParaPapel(materiais, "hardware").length > 0;

  /*
   * O berço continua valendo para todo modelo, e isso é deliberado: espuma e
   * nicho servem tanto para acomodar joia em caixa rígida quanto para proteger
   * peça frágil dentro de uma caixa de envio.
   */
  const podeBerco = materiaisParaPapel(materiais, "cradle").length > 0;

  const temBerco = componentes.some((c) => c.role === "cradle");

  return (
    <section className="space-y-3">
      <header className="flex items-center gap-2">
        <Boxes className="size-4 text-brand" />
        <h2 className="text-sm font-semibold">Lista de materiais</h2>
      </header>

      <p className="text-xs text-muted-foreground">
        O que entra na caixa além do papelão da estrutura. Tudo daqui vai para a
        ficha técnica — é o que a produção separa no estoque.
      </p>

      {componentes.length > 0 && (
        <div className="space-y-2">
          {componentes.map((c) => (
            <LinhaDeMaterial
              key={c.id}
              componente={c}
              materiais={materiais}
              currency={currency}
            />
          ))}
        </div>
      )}

      {/*
        Os botões que o modelo não comporta somem, e não aparecem cinzas.

        Desabilitado com explicação serve para falta de CADASTRO, que é coisa
        que o usuário resolve. Aqui a ausência é permanente enquanto o modelo
        for este, e um botão eternamente apagado só ensina a ignorar a fileira.
      */}
      <div className="flex flex-wrap gap-2">
        {/* `isRigid` já exclui o modelo livre — ele não é cartonagem rígida. */}
        {isRigid(boxModel) && (
          <BotaoAdicionar
            rotulo="Revestimento"
            habilitado={podeRevestir}
            motivo="Cadastre um papel ou tecido medido em m²."
            onClick={() => addComponent("wrap")}
          />
        )}

        {isMagnet(boxModel) && (
          <BotaoAdicionar
            rotulo="Ferragem"
            habilitado={podeFerragem}
            motivo="Cadastre um material cotado por peça (ímã, fecho, fita)."
            onClick={() => addComponent("hardware")}
          />
        )}

        <BotaoAdicionar
          rotulo="Berço"
          habilitado={podeBerco}
          motivo="Cadastre uma espuma em m³ ou um papelão em m²."
          onClick={() => addComponent("cradle")}
        />
      </div>

      {/* Os parâmetros do berço vivem fora da linha porque descrevem a
          CONSTRUÇÃO e não o material: a mesma espuma serve a berços de alturas
          diferentes, e a grade não é propriedade do papelão. */}
      {temBerco && <ParametrosDoBerco />}
    </section>
  );
}

function BotaoAdicionar({
  rotulo,
  habilitado,
  motivo,
  onClick,
}: {
  rotulo: string;
  habilitado: boolean;
  motivo: string;
  onClick: () => void;
}) {
  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className="gap-1.5"
      onClick={onClick}
      disabled={!habilitado}
      // O botão desabilitado diz POR QUE está desabilitado. Sem isso, a pessoa
      // conclui que o sistema não faz ferragem, quando faltava um cadastro.
      title={habilitado ? undefined : motivo}
    >
      <Plus className="size-3.5 text-brand" />
      {rotulo}
    </Button>
  );
}

const ROTULO_PAPEL: Record<ComponentInput["role"], string> = {
  wrap: "Revestimento",
  hardware: "Ferragem",
  cradle: "Berço",
};

function LinhaDeMaterial({
  componente,
  materiais,
  currency,
}: {
  componente: ComponentInput;
  materiais: Material[];
  currency: string;
}) {
  const { updateComponent, removeComponent } = useQuoteStore(
    useShallow((s) => ({
      updateComponent: s.updateComponent,
      removeComponent: s.removeComponent,
    })),
  );

  const elegiveis = materiaisParaPapel(materiais, componente.role);
  const material = materiais.find((m) => m.id === componente.material_id);

  /*
   * O custo mostrado é o do MATERIAL na grandeza dele, não o custo na caixa.
   *
   * Quanto de revestimento a caixa consome depende da planificação, e quem
   * calcula isso é o motor — repetir a conta aqui criaria uma segunda
   * aritmética para divergir. O que a linha responde é mais simples e é o que
   * falta na hora de escolher: "este ímã custa R$ 0,85 a peça".
   */
  const unitario =
    componente.role === "hardware"
      ? material?.cost_per_piece
      : componente.role === "cradle"
        ? (material?.cost_per_m3 ?? material?.cost_per_m2)
        : material?.cost_per_m2;

  const grandeza =
    componente.role === "hardware"
      ? "un"
      : componente.role === "cradle" && material?.cost_per_m3 != null
        ? "m³"
        : "m²";

  return (
    <div className="space-y-2 rounded-lg border p-3">
      <div className="flex items-center gap-2">
        <span className="text-xs font-medium text-muted-foreground">
          {ROTULO_PAPEL[componente.role]}
        </span>

        {unitario != null && (
          <span className="ml-auto font-mono text-xs tabular-nums text-muted-foreground">
            {formatCurrency(unitario, currency, 4)}/{grandeza}
          </span>
        )}

        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="size-7 text-muted-foreground hover:text-destructive"
          onClick={() => removeComponent(componente.id)}
          aria-label={`Remover ${ROTULO_PAPEL[componente.role].toLowerCase()}`}
        >
          <Trash2 className="size-3.5" />
        </Button>
      </div>

      <div className="flex gap-2">
        <Select
          value={String(componente.material_id)}
          onValueChange={(valor) =>
            updateComponent(componente.id, { material_id: Number(valor) })
          }
        >
          <SelectTrigger className="h-8 flex-1 text-xs">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {elegiveis.map((m) => (
              <SelectItem key={m.id} value={String(m.id)}>
                {m.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {/* Só ferragem se conta. "Quantos revestimentos" é pergunta sem
            resposta, e o berço é um só — a grade é que o descreve. */}
        {componente.role === "hardware" && (
          <div className="flex items-center gap-1.5">
            <Input
              type="number"
              min={0}
              step="0.5"
              className="h-8 w-20 text-xs tabular-nums"
              value={componente.quantity ?? 1}
              onChange={(e) =>
                updateComponent(componente.id, { quantity: Number(e.target.value) })
              }
              aria-label="Quantidade por caixa"
            />
            <span className="text-xs text-muted-foreground">/ caixa</span>
          </div>
        )}
      </div>
    </div>
  );
}

/** As construções de berço. ⚠️ Espelha o enum CradleType do PHP. */
const TIPOS_DE_BERCO: Array<{ value: CradleType; label: string }> = [
  { value: "foam", label: "Espuma escavada" },
  { value: "board_niche", label: "Nicho de papelão" },
  { value: "paper_niche", label: "Nicho de papel" },
  { value: "paper_fold", label: "Papel dobrado" },
  { value: "divider_grid", label: "Grade de divisórias" },
];

/** Tipos cuja conta usa a grade. Nos demais os campos ficariam inertes. */
const COM_GRADE: CradleType[] = ["board_niche", "paper_niche", "divider_grid"];

function ParametrosDoBerco() {
  const { spec, updateSpec } = useQuoteStore(
    useShallow((s) => ({ spec: s.spec, updateSpec: s.updateSpec })),
  );

  const tipo = spec.cradle_type ?? null;
  const usaGrade = tipo !== null && COM_GRADE.includes(tipo);

  return (
    <div className="space-y-3 rounded-lg border border-dashed p-3">
      <p className="text-xs font-medium">Construção do berço</p>

      <div className="space-y-1.5">
        <Label className="text-xs">Tipo</Label>
        <Select
          value={tipo ?? ""}
          onValueChange={(valor) => updateSpec({ cradle_type: valor as CradleType })}
        >
          <SelectTrigger className="h-8 text-xs">
            <SelectValue placeholder="Escolha a construção" />
          </SelectTrigger>
          <SelectContent>
            {TIPOS_DE_BERCO.map((t) => (
              <SelectItem key={t.value} value={t.value}>
                {t.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {/* Sem tipo o servidor devolve berço nulo e não cobra nada. Dizer isso
            é melhor que deixar o usuário achar que o material bastou. */}
        {tipo === null && (
          <p className="text-xs text-amber-600 dark:text-amber-500">
            Escolha a construção — sem ela o berço não entra no preço.
          </p>
        )}
      </div>

      {usaGrade && (
        <div className="grid grid-cols-2 gap-2">
          <div className="space-y-1.5">
            <Label className="text-xs">Linhas</Label>
            <Input
              type="number"
              min={1}
              max={20}
              className="h-8 text-xs tabular-nums"
              value={spec.cradle_rows ?? 1}
              onChange={(e) => updateSpec({ cradle_rows: Number(e.target.value) })}
            />
          </div>
          <div className="space-y-1.5">
            <Label className="text-xs">Colunas</Label>
            <Input
              type="number"
              min={1}
              max={20}
              className="h-8 text-xs tabular-nums"
              value={spec.cradle_columns ?? 1}
              onChange={(e) => updateSpec({ cradle_columns: Number(e.target.value) })}
            />
          </div>
        </div>
      )}

      <div className="space-y-1.5">
        <Label className="text-xs">
          Altura — {Math.round((spec.cradle_height_ratio ?? 1) * 100)}% da caixa
        </Label>
        <Input
          type="range"
          min={10}
          max={100}
          step={5}
          className="h-8"
          value={Math.round((spec.cradle_height_ratio ?? 1) * 100)}
          onChange={(e) =>
            updateSpec({ cradle_height_ratio: Number(e.target.value) / 100 })
          }
        />
      </div>
    </div>
  );
}

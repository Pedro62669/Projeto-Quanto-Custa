"use client";

import { useEffect, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import { toast } from "sonner";

import { api, type QuoteListItem } from "@/lib/api";
import { mensagemDeErro } from "@/hooks/useApi";
import type { BoxModel, CustomPartInput, Material, PricingMode } from "@/lib/pricing/types";
import { useQuoteStore } from "@/store/useQuoteStore";

/** O orçamento sendo reaberto, e em que modo. */
export interface Reabertura {
  id: number;
  referencia: string;
  /** `editar` grava por cima; `duplicar` cria um orçamento novo. */
  modo: "duplicar" | "editar";
}

/**
 * Devolve as peças do modelo livre com custo e perda resolvidos.
 *
 * Os dois números descem do MATERIAL, como no servidor — o orçamento guarda a
 * medida da peça, não quanto custa o metro quadrado dela.
 *
 * Peça cujo material saiu do cadastro é DESCARTADA, e não recriada com custo
 * zero: zero é um custo, e um custo de zero precifica a peça como se o papelão
 * fosse de graça. Um orçamento com uma peça a menos é visível; um com uma peça
 * grátis não é.
 */
function reconstruirPecas(
  gravadas: NonNullable<QuoteListItem["custom_parts"]>,
  materiais: Material[],
): CustomPartInput[] {
  return gravadas.flatMap((p) => {
    const material = materiais.find((m) => m.id === p.material_id);

    if (!material || material.cost_per_m2 === null) return [];

    return [{
      // Identidade só do cliente: sem chave estável, remover a peça #2 de uma
      // lista de quatro faria o cursor pular de linha no meio da digitação.
      id: crypto.randomUUID(),
      material_id: p.material_id,
      name: p.name,
      role: p.role as CustomPartInput["role"],
      cost_per_m2: material.cost_per_m2,
      waste_percent: material.default_waste_percent,
      width_mm: p.width_mm,
      length_mm: p.length_mm,
      quantity: p.quantity,
    }];
  });
}

/**
 * Carrega um orçamento gravado na calculadora.
 *
 * A calculadora aceita `?duplicar=12` e `?editar=12`. A URL é o transporte
 * porque o destino é outra página: passar por store global obrigaria a
 * calculadora a saber que existe uma tela de orçamento, e um recarregamento
 * perderia o estado sem deixar rastro de por quê.
 *
 * Duplicar vale para qualquer estado; editar, só rascunho — quem garante isso é
 * o servidor, que recusa com 422. A tela do orçamento só oferece o botão onde
 * ele funciona, mas a URL é digitável e a guarda de verdade tem que estar lá.
 */
export function useReabrirOrcamento(pronto: boolean): Reabertura | null {
  const parametros = useSearchParams();
  const carregarDe = useQuoteStore((s) => s.carregarDe);
  const materiais = useQuoteStore((s) => s.materials);

  const [reabertura, setReabertura] = useState<Reabertura | null>(null);

  const duplicar = parametros.get("duplicar");
  const editar = parametros.get("editar");
  const id = Number(editar ?? duplicar);

  /*
   * Carregado uma vez por id.
   *
   * Sem a trava, o efeito reagiria a `carregarDe` e a qualquer nova renderização
   * e sobrescreveria o que a pessoa acabou de digitar — o orçamento reabriria
   * sozinho no meio da edição.
   */
  const carregado = useRef<number | null>(null);

  useEffect(() => {
    // Espera o bootstrap: sem materiais e parâmetros carregados, o preview sai
    // vazio e o formulário abre sem o material selecionado.
    if (!pronto || !Number.isFinite(id) || id <= 0) return;
    if (carregado.current === id) return;

    carregado.current = id;

    void (async () => {
      try {
        const q = await api.quotes.get(id);

        carregarDe({
          material_id: q.specification.material_id,
          box_model: q.specification.box_model as BoxModel,
          width_mm: q.specification.width_mm,
          height_mm: q.specification.height_mm,
          depth_mm: q.specification.depth_mm,
          quantity: q.specification.quantity,

          lid_width_mm: q.specification.lid_width_mm,
          lid_depth_mm: q.specification.lid_depth_mm,
          lid_height_mm: q.specification.lid_height_mm,

          // Perda, minutos, margem e modo vivem em `parameters` porque não
          // descrevem a peça — a mesma caixa com outra margem continua a mesma
          // caixa. Reabrir junta os dois blocos.
          waste_percent: q.parameters.waste_percent,
          production_minutes_per_unit: q.parameters.production_minutes_per_unit,
          profit_margin_percent: q.parameters.profit_margin_percent,
          pricing_mode: q.parameters.pricing_mode as PricingMode,

          custom_parts: reconstruirPecas(q.custom_parts ?? [], materiais),
        });

        setReabertura({
          id,
          referencia: q.reference,
          modo: editar ? "editar" : "duplicar",
        });
      } catch (erro) {
        // A trava fica levantada de propósito: repetir uma busca que falhou a
        // cada render viraria um laço de requisições contra um id que não
        // existe.
        toast.error(mensagemDeErro(erro));
      }
    })();
  }, [pronto, id, editar, carregarDe, materiais]);

  return reabertura;
}

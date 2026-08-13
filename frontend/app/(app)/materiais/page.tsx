"use client";

import { useState } from "react";
import { Pencil, Plus } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

import { PageHeader } from "@/components/PageHeader";
import {
  DataTable,
  EmptyState,
  Pagination,
  type Column,
} from "@/components/data/DataTable";
import { MaterialSheet } from "@/components/materiais/MaterialSheet";

import { useApi } from "@/hooks/useApi";
import { useDebounce } from "@/hooks/useDebounce";
import { api, type MaterialAdmin } from "@/lib/api";
import { formatCurrency } from "@/lib/pricing/engine";
import { ROTULO_TIPO_MATERIAL } from "@/lib/rotulos";

/**
 * Cadastro de matéria-prima.
 *
 * É a tela que faltava para a calculadora sair do lugar: sem ela, a empresa
 * ficava presa aos materiais que vieram no seeder — nenhum deles é o papelão
 * que ela de fato compra.
 */
export default function MateriaisPage() {
  const [busca, setBusca] = useState("");
  const [pagina, setPagina] = useState(1);
  const [emEdicao, setEmEdicao] = useState<MaterialAdmin | null>(null);
  const [formAberto, setFormAberto] = useState(false);

  // Busca NO SERVIDOR, em debounce. O índice de admin pagina, então filtrar
  // no cliente encontraria apenas dentro da página aberta — e o material que
  // ficou na página 2 simplesmente não apareceria.
  const buscaAtrasada = useDebounce(busca, 400);

  const lista = useApi(`materiais:${pagina}:${buscaAtrasada}`, () =>
    api.materials.list({ page: pagina, search: buscaAtrasada || undefined }),
  );

  function abrirNovo() {
    setEmEdicao(null);
    setFormAberto(true);
  }

  function abrirEdicao(material: MaterialAdmin) {
    setEmEdicao(material);
    setFormAberto(true);
  }

  const colunas: Column<MaterialAdmin>[] = [
    {
      header: "Material",
      render: (m) => (
        <div className="flex items-center gap-2">
          {/* A mesma cor que o 3D usa: liga o cadastro ao que aparece na tela. */}
          <span
            className="size-3 shrink-0 rounded-sm border"
            style={{ backgroundColor: m.color_hex }}
            aria-hidden
          />
          <div className="min-w-0">
            <p className="truncate font-medium">{m.name}</p>
            <p className="text-xs text-muted-foreground">
              {ROTULO_TIPO_MATERIAL[m.type] ?? m.type}
              {m.thickness_mm ? ` · ${m.thickness_mm}mm` : ""}
            </p>
          </div>
        </div>
      ),
    },
    {
      header: "Custo",
      className: "text-right",
      render: (m) => (
        <div>
          {/*
            O custo por m² é o que entra na conta; o preço de compra é o que a
            empresa paga. Mostrar só um dos dois esconde metade da história —
            especialmente quando o material é cotado em quilo.
          */}
          <p className="font-mono tabular-nums">
            {m.cost_per_m2 === null ? (
              <span className="text-muted-foreground">sem área</span>
            ) : (
              `${formatCurrency(m.cost_per_m2, "BRL", 4)}/m²`
            )}
          </p>
          <p className="font-mono text-xs text-muted-foreground tabular-nums">
            {formatCurrency(m.cost_per_unit, "BRL", 4)}/{m.cost_unit}
          </p>
        </div>
      ),
    },
    {
      header: "Perda",
      className: "text-right",
      render: (m) => (
        <span className="font-mono tabular-nums">{m.default_waste_percent}%</span>
      ),
    },
    {
      header: "Folha",
      render: (m) =>
        m.sheet_width_mm && m.sheet_length_mm ? (
          <span className="font-mono text-xs tabular-nums">
            {m.sheet_width_mm} × {m.sheet_length_mm}
          </span>
        ) : (
          // Sem folha não há plano de corte. Dizer isso na lista é o que leva
          // alguém a completar o cadastro.
          <span className="text-xs text-muted-foreground">sem plano de corte</span>
        ),
    },
    {
      header: "",
      className: "w-24 text-right",
      render: (m) => (
        <div className="flex items-center justify-end gap-1">
          {!m.is_active && (
            <Badge variant="outline" className="text-[10px]">
              inativo
            </Badge>
          )}
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={`Editar ${m.name}`}
            onClick={() => abrirEdicao(m)}
          >
            <Pencil />
          </Button>
        </div>
      ),
    },
  ];

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <PageHeader
        title="Materiais"
        description="O custo daqui multiplica a área de toda caixa. Cadastre com a nota na mão: lote e frete dão o número mais fiel."
        actions={
          <Button onClick={abrirNovo}>
            <Plus className="size-4" />
            Novo material
          </Button>
        }
      />

      {/* Ativos e inativos na mesma lista, com selo: é o que o endpoint
          devolve, e um filtro que não existe no servidor seria um controle
          que não faz nada. */}
      <Input
        placeholder="Buscar pelo nome…"
        value={busca}
        onChange={(e) => {
          setBusca(e.target.value);
          setPagina(1);
        }}
        className="max-w-xs"
        aria-label="Buscar material"
      />

      <DataTable
        columns={colunas}
        rows={lista.data?.items ?? []}
        loading={lista.loading}
        error={lista.error}
        onRetry={lista.refetch}
        rowKey={(m) => m.id}
        empty={
          busca ? (
            <EmptyState
              title="Nenhum material com esse nome"
              description="Limpe a busca para ver a lista inteira."
            />
          ) : (
            <EmptyState
              title="Nenhum material cadastrado"
              description="A calculadora precisa de ao menos um insumo medido em área — papelão ou papel — para calcular um preço."
              action={
                <Button size="sm" onClick={abrirNovo}>
                  <Plus className="size-3.5" />
                  Cadastrar o primeiro
                </Button>
              }
            />
          )
        }
      />

      <Pagination page={lista.data} onChange={setPagina} />

      {/*
        `key` remonta o formulário a cada material: sem isso, abrir "editar" logo
        depois de "novo" reaproveitaria o estado anterior e mostraria os campos
        do registro errado.
      */}
      {formAberto && (
        <MaterialSheet
          key={emEdicao?.id ?? "novo"}
          material={emEdicao}
          open={formAberto}
          onOpenChange={setFormAberto}
          // A calculadora relê os materiais toda vez que é aberta, então o
          // insumo novo já aparece lá sem nenhum aviso ao usuário.
          onSaved={lista.refetch}
        />
      )}
    </div>
  );
}

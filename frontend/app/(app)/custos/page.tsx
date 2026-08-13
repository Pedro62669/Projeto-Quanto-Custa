"use client";

import { PageHeader } from "@/components/PageHeader";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";

import { AbaParametros } from "@/components/custos/AbaParametros";
import { AbaCustosFixos } from "@/components/custos/AbaCustosFixos";
import { AbaEquipamentos } from "@/components/custos/AbaEquipamentos";
import { AbaHoraEmpresa } from "@/components/custos/AbaHoraEmpresa";

/**
 * Custos da empresa — o que transforma medida em preço.
 *
 * Quatro abas na ordem em que a informação se acumula: os parâmetros gerais, as
 * despesas fixas do mês, o parque de máquinas, e a hora-empresa que sai da soma
 * dos três. A última é o diferencial do produto: em vez de chutar um valor de
 * mão de obra por hora, a empresa descobre quanto custa CADA MINUTO seu, com o
 * aluguel e a energia rateados pelas horas que de fato produzem.
 */
export default function CustosPage() {
  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <PageHeader
        title="Custos"
        description="Os números que transformam medida em preço. Publicar cria uma versão nova — orçamentos já gravados não mudam de valor."
      />

      <Tabs defaultValue="parametros">
        <TabsList>
          <TabsTrigger value="parametros">Parâmetros</TabsTrigger>
          <TabsTrigger value="fixos">Custos fixos</TabsTrigger>
          <TabsTrigger value="equipamentos">Equipamentos</TabsTrigger>
          <TabsTrigger value="hora">Hora-empresa</TabsTrigger>
        </TabsList>

        <TabsContent value="parametros">
          <AbaParametros />
        </TabsContent>
        <TabsContent value="fixos">
          <AbaCustosFixos />
        </TabsContent>
        <TabsContent value="equipamentos">
          <AbaEquipamentos />
        </TabsContent>
        <TabsContent value="hora">
          <AbaHoraEmpresa />
        </TabsContent>
      </Tabs>
    </div>
  );
}

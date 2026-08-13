import type { QuoteStatusValue } from "@/lib/api";

/**
 * Os rótulos que o usuário lê, por valor de enum do servidor.
 *
 * Ficam num arquivo só porque aparecem em cinco telas — lista de orçamentos,
 * detalhe, painel inicial, financeiro e ficha técnica. Espalhados, o dia em que
 * "rejected" virar "recusado" a mudança pega três dos cinco.
 *
 * ⚠️ Espelham os enums do PHP. Um valor novo lá aparece aqui como `undefined`,
 * então o acesso é sempre com recuo para o próprio valor bruto.
 */

export const ROTULO_STATUS: Record<QuoteStatusValue, string> = {
  draft: "Rascunho",
  sent: "Enviado",
  approved: "Aprovado",
  rejected: "Recusado",
};

/** Tom do selo. Aprovado é a única conclusão positiva do fluxo. */
export const TOM_STATUS: Record<
  QuoteStatusValue,
  "default" | "secondary" | "outline" | "destructive"
> = {
  draft: "outline",
  sent: "secondary",
  approved: "default",
  rejected: "destructive",
};

export const ROTULO_MODELO: Record<string, string> = {
  rsc: "Caixa americana (RSC)",
  tray: "Caixa com tampa",
  sleeve: "Luva / cinta",
  pouch: "Saco / envelope",
  tube: "Tubo / lata",
  drawer: "Caixa gaveta",
  mailer: "Mailer box",
  rigid_telescopic: "Rígida telescópica",
  rigid_book: "Rígida livro",
  rigid_book_flap: "Rígida livro com aba",
  rigid_magnet: "Rígida com ímã",
  rigid_magnet_side: "Rígida com ímã lateral",
  rigid_magnet_wrap: "Rígida com ímã envolvente",
  free: "Modelo livre",
};

export const ROTULO_UNIDADE: Record<string, string> = {
  m2: "Metro quadrado",
  kg: "Quilo",
  un: "Peça",
  m3: "Metro cúbico (bloco)",
};

export const ROTULO_TIPO_MATERIAL: Record<string, string> = {
  cardboard: "Papelão",
  paper: "Papel",
  fabric: "Tecido",
  plastic: "Plástico",
  hardware: "Ferragem",
  other: "Outro",
};

/**
 * Sentido da fibra.
 *
 * O texto diz a CONSEQUÊNCIA, não só o nome: entre economizar chapa e entregar
 * uma caixa empenada, quem escolhe precisa saber o que está escolhendo.
 */
export const ROTULO_FIBRA: Record<string, string> = {
  none: "Sem fibra — a peça pode girar 90°",
  length: "Fibra no comprimento — não gira",
  width: "Fibra na largura — não gira",
};

export const ROTULO_CATEGORIA: Record<string, string> = {
  quote_sale: "Venda de orçamento",
  product_sale: "Venda de produto",
  material_purchase: "Compra de material",
  fixed_cost: "Custo fixo",
  other: "Outro",
};

export const ROTULO_PLANO: Record<string, string> = {
  free: "Gratuito",
  basic: "Básico",
  pro: "Profissional",
};

export const ROTULO_SITUACAO_PLANO: Record<string, string> = {
  trialing: "Em teste",
  active: "Ativa",
  past_due: "Pagamento pendente",
  canceled: "Cancelada",
};

/** Data ISO (YYYY-MM-DD ou ISO completo) em dd/mm/aaaa. */
export function formatarData(iso: string | null | undefined): string {
  if (!iso) return "—";

  // `split` em vez de `new Date`: uma data pura ("2026-08-10") é interpretada
  // como UTC pelo construtor, e exibiria o dia anterior a oeste de Greenwich.
  const [ano, mes, dia] = iso.slice(0, 10).split("-");

  return `${dia}/${mes}/${ano}`;
}

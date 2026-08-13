import {
  ArrowLeftRight,
  Boxes,
  Building2,
  CalendarClock,
  Calculator,
  Coins,
  CreditCard,
  FileText,
  LayoutDashboard,
  Package,
  TrendingUp,
  Truck,
  UserCog,
  Users,
  type LucideIcon,
} from "lucide-react";

export interface NavItem {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Só para o admin da empresa — cadastros, custos e cobrança. */
  adminOnly?: boolean;
}

export interface NavGroup {
  label: string;
  items: NavItem[];
}

/**
 * A navegação, definida uma vez.
 *
 * Barra lateral e gaveta do celular leem daqui: duas listas iguais escritas em
 * dois lugares divergem no primeiro módulo novo, e o item que falta é sempre o
 * que só existe numa das duas telas.
 *
 * A ordem dos grupos segue o fluxo do trabalho — orçar, cadastrar o que o
 * orçamento consome, acompanhar o dinheiro, cuidar da empresa —, não a ordem em
 * que as telas foram construídas.
 */
export const NAVEGACAO: NavGroup[] = [
  {
    label: "Orçar",
    items: [
      { href: "/painel", label: "Painel", icon: LayoutDashboard },
      { href: "/calculadora", label: "Calculadora", icon: Calculator },
      { href: "/orcamentos", label: "Orçamentos", icon: FileText },
    ],
  },
  {
    label: "Cadastros",
    items: [
      { href: "/materiais", label: "Materiais", icon: Boxes, adminOnly: true },
      { href: "/clientes", label: "Clientes", icon: Users },
      { href: "/fornecedores", label: "Fornecedores", icon: Truck },
      { href: "/produtos", label: "Produtos", icon: Package },
    ],
  },
  {
    label: "Financeiro",
    items: [
      { href: "/financeiro", label: "Painel", icon: TrendingUp },
      { href: "/financeiro/lancamentos", label: "Lançamentos", icon: ArrowLeftRight },
      { href: "/financeiro/parcelas", label: "Parcelas", icon: CalendarClock },
    ],
  },
  {
    label: "Empresa",
    items: [
      { href: "/empresa", label: "Perfil", icon: Building2, adminOnly: true },
      { href: "/custos", label: "Custos", icon: Coins, adminOnly: true },
      { href: "/usuarios", label: "Usuários", icon: UserCog, adminOnly: true },
      { href: "/assinatura", label: "Assinatura", icon: CreditCard, adminOnly: true },
    ],
  },
];

/**
 * O item ativo.
 *
 * A barra terminal importa: sem ela, `/financeiro` acenderia junto com
 * `/financeiro/parcelas` — e dois itens acesos ao mesmo tempo fazem o menu
 * mentir sobre onde a pessoa está.
 *
 * A raiz não aparece nesta lista: `/` é a página pública de vendas, fora da
 * casca autenticada. O painel mora em `/painel` justamente para que a porta de
 * entrada do site pertença a quem ainda não é cliente.
 */
export function estaAtivo(href: string, caminho: string): boolean {
  return caminho === href || caminho.startsWith(`${href}/`);
}

import { redirect } from "next/navigation";

/**
 * A raiz não tem conteúdo próprio — o produto é a calculadora.
 *
 * O redirect roda no servidor (Server Component), evitando o flash de uma
 * página vazia antes do JavaScript carregar. Quem não tiver sessão é mandado
 * para /login pelo guard da própria calculadora.
 */
export default function HomePage() {
  redirect("/calculadora");
}

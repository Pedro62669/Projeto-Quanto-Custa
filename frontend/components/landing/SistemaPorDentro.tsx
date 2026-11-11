import Image, { type StaticImageData } from "next/image";

import calculadora from "@/public/capturas/calculadora.png";
import horaEmpresa from "@/public/capturas/hora-empresa.png";
import planoDeCorte from "@/public/capturas/plano-de-corte.png";

/**
 * O sistema por dentro — as telas reais, não desenhos delas.
 *
 * ## Por que esta seção existe
 *
 * O produto deixou de ter teste grátis, e não há cliente para depor. Some a
 * prova de terceiros e some a prova por experiência própria; o que sobra é a
 * única que o produto ainda pode dar de si mesmo: **mostrar-se**. Uma página que
 * só descreve funcionalidades pede fé, e pedir fé antes de pedir cartão é o
 * caminho mais curto para o visitante fechar a aba.
 *
 * ## As imagens são geradas, não desenhadas
 *
 * Saem de `npm run capturas`, que dirige o sistema rodando e fotografa as telas
 * (ver `e2e/capturas.mjs`). Isso resolve o problema que mata toda captura de
 * landing: a interface muda, a imagem não, e a página passa a vender uma tela
 * que não existe mais — sem que nada quebre para denunciar.
 *
 * ## O texto NÃO repete os números da imagem
 *
 * Tentador escrever "de 12% para 46,59%", que é o que a captura mostra hoje. Mas
 * os números vêm do orçamento que estava no banco na hora da captura, e mudam na
 * próxima geração. O texto descreve o MECANISMO, que não muda; os números ficam
 * onde podem ser conferidos, dentro da imagem.
 *
 * ## Ordem
 *
 * Espelha a seção "Três contas que a planilha erra", que vem logo acima: lá está
 * o problema, aqui está a tela que o resolve, na mesma ordem. A pessoa lê a
 * queixa e vê a resposta sem precisar guardar nada na cabeça.
 */
export function SistemaPorDentro() {
  const telas: Array<{
    imagem: StaticImageData;
    alt: string;
    titulo: string;
    texto: string;
  }> = [
    {
      imagem: calculadora,
      alt: "A calculadora do quantoCusta: medidas à esquerda, a caixa em 3D no centro e a composição do preço à direita",
      titulo: "O preço enquanto você digita",
      texto:
        "Medidas, material, quantidade e margem de um lado; a caixa em 3D no centro; o preço e a composição do outro. Mudou uma medida, os três acompanham. É esta tela que a conta nova abre — com uma caixa de exemplo já calculada.",
    },
    {
      imagem: planoDeCorte,
      alt: "Plano de corte: perda orçada, perda real e a diferença entre as duas, com o arranjo das peças na chapa",
      titulo: "A perda que a planilha não vê",
      texto:
        "O sistema encaixa as peças na chapa de verdade, descontando a lâmina a cada corte, e põe lado a lado a perda que você orçou e a que o corte gerou. A diferença entre as duas sai do seu lucro — e é a conta que nenhuma planilha faz.",
    },
    {
      imagem: horaEmpresa,
      alt: "Aba hora-empresa: jornada, eficiência e o custo do minuto calculado a partir dos custos fixos e da depreciação",
      titulo: "Quanto custa o seu minuto",
      texto:
        "Aluguel, energia, salários e a depreciação das máquinas viram um custo por minuto de produção. Em três cenários de eficiência, porque ninguém produz 100% do horário — e é essa diferença que some quando se divide o salário por 220.",
    },
  ];

  return (
    <section id="por-dentro" className="scroll-mt-20 border-b border-border/60">
      <div className="mx-auto max-w-6xl px-5 py-16 lg:py-20">
        <h2 className="max-w-2xl text-2xl font-semibold tracking-tight sm:text-3xl">
          As telas, sem maquiagem
        </h2>
        <p className="mt-3 max-w-2xl text-muted-foreground">
          São capturas do sistema rodando, com números que fecham entre si. É o
          que você encontra ao criar a conta — antes de assinar.
        </p>

        {/*
          Uma coluna, imagem em toda a largura — e não texto ao lado da captura.

          A primeira versão punha os dois lado a lado, e a captura ficava com
          640 px. Interface densa nessa largura vira borrão: o preço, a
          composição e os rótulos das peças — que são o argumento — deixam de
          ser legíveis, e sobra uma imagem bonita que não prova nada. Empilhado,
          a captura ganha os ~1100 px de que precisa para ser LIDA.

          O texto vem antes da imagem, curto, com largura de leitura própria: ele
          diz o que procurar antes de a pessoa olhar.
        */}
        <div className="mt-12 space-y-16 lg:space-y-24">
          {telas.map((tela) => (
            <figure key={tela.titulo}>
              <figcaption className="max-w-2xl">
                <h3 className="text-lg font-medium leading-snug">{tela.titulo}</h3>
                <p className="mt-2.5 text-sm leading-relaxed text-muted-foreground">
                  {tela.texto}
                </p>
              </figcaption>

              <div className="mt-6 overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <Image
                  src={tela.imagem}
                  alt={tela.alt}
                  placeholder="blur"
                  /*
                    `sizes` é o que evita servir a variante de 1280 px para um
                    celular. Sem ele o Next assume 100vw e manda a maior para
                    todo mundo — numa página cujo trabalho é carregar antes de a
                    pessoa decidir sair.
                  */
                  sizes="(min-width: 1152px) 1104px, 100vw"
                  className="h-auto w-full"
                />
              </div>
            </figure>
          ))}
        </div>
      </div>
    </section>
  );
}

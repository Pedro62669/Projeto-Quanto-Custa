# caixa-envio.blend

A caixa de envio modelada: uma americana (RSC, FEFCO 0201) com quatro paredes
numa tira, quatro abas em cima e quatro embaixo, e a dobra completa animada da
chapa plana até a caixa fechada.

## Este arquivo é REFERÊNCIA, não é carregado pelo site

Diferente de [`mailer/box-mailer.blend`](../mailer/), que vira `mailer.glb` e é
carregado em tempo de execução, a caixa de envio é **desenhada em código** —
ver `CaixaEnvioMesh` em
[`frontend/components/three/BoxMesh.tsx`](../frontend/components/three/BoxMesh.tsx).

Houve uma tentativa de exportar para glTF e ela foi desfeita. Duas razões, nesta
ordem:

1. **A dobra não sobrevive à exportação.** Aqui ela é feita por `HOOK`: uma
   malha única e onze modificadores que puxam vértices atrás dos empties
   `Hinge_*`. O glTF anima nó, osso e morph target — hook não existe. A
   exportação direta sai com as 11 rotações nos empties e a malha presa a
   nenhum deles: os vincos giram no vazio e a chapa fica plana para sempre.

   Isso tinha conserto (separar a malha em doze partes e parentear cada uma ao
   seu vinco, que é como a mailer já nasce). O que não tinha conserto foi o
   item seguinte.

2. **O modelo assado se deforma ao ser redimensionado.** O arquivo tem uma
   medida só, e a tela o estica por eixo até a que o usuário digitou. Painel
   alinhado aos eixos aguenta; painel GIRADO, não — escalar mais um eixo que o
   outro cisalha o retângulo em paralelogramo. A mailer convive com isso porque
   só a tampa gira. Aqui giram as oito abas, e na pose aberta as quatro de cima
   ficariam permanentemente tortas.

Um RSC são doze retângulos. Desenhá-lo em código custa uma função e resolve os
dois problemas: cada painel nasce com a medida certa em qualquer proporção, a
espessura é igual nos quatro lados, e nada cisalha.

## O que o código herdou daqui

| Deste arquivo | Onde vive hoje |
| --- | --- |
| Construção: 4 paredes, 4 abas em cima, 4 embaixo | `CaixaEnvioMesh` |
| Aba fechada, deitada sobre a caixa | `ABA_FECHADA` (−90°) |
| Aba aberta, passando da horizontal | `ABA_ABERTA` (+120°, quadro 90) |
| Abas internas fecham primeiro, as externas pousam por cima | `CURSO_INTERNA` / `CURSO_EXTERNA` |

Sobre a ordem: nas chaves de `Hinge_TopFlap_*`, abrindo, as externas saem nos
quadros 1→20 e as internas só depois, 20→40. Fechando é o inverso — e é a única
ordem possível, porque a aba de baixo teria que atravessar a de cima para chegar
ao lugar.

As faixas no código **não** copiam esses quadros. Aqui as externas gastariam os
primeiros 21% do slider e depois metade do curso ficaria morta; `CURSO_EXTERNA` e
`CURSO_INTERNA` repartem o curso inteiro e se cruzam de leve no meio, mantendo a
ordem sem o tempo parado.

Mexeu nos ângulos ou na ordem da dobra aqui? Atualize as constantes lá — não há
build que faça isso sozinho.

## Abrir

```
blender caixa-envio/caixa-envio.blend
```

Quadro 1 é a caixa fechada; 90, montada com as abas escancaradas; 215, a chapa
plana. A cena roda a 24 fps.

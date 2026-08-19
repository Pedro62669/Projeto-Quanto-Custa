# caixa-ima.blend

A caixa ímã: capa de livro com aba frontal e fecho magnético. Base, tampa e a
aba que desce sobre a parede da frente, com a abertura animada em 120 quadros.

## Este arquivo é REFERÊNCIA, não é carregado pelo site

Como [`caixa-envio/`](../caixa-envio/), e pela mesma razão de fundo: o modelo é
**desenhado em código** — ver `LivroMesh` em
[`frontend/components/three/BoxMesh.tsx`](../frontend/components/three/BoxMesh.tsx),
que atende toda a família rígida de livro (`isBook`), incluindo as três caixas
ímã.

Quem é carregado em tempo de execução é só a mailer, e ela precisou de um
tratamento inteiro para sobreviver ao redimensionamento — o `.glb` tem UMA
medida, e esticar por eixo cisalha painel girado. A solução foi escalar cada um
dos 23 painéis no espaço dele. Um modelo assado a mais significaria repetir esse
trabalho a cada caixa nova.

Desenhar custa uma função e resolve de uma vez: cada painel nasce com a medida
certa em qualquer proporção, e nada cisalha.

## O que o código herdou daqui

| Deste arquivo | Onde vive hoje |
| --- | --- |
| Base, tampa e aba frontal como peças separadas | `LivroMesh` |
| A aba desce sobre a parede da frente ao fechar | `hasClosingFlap()` / `BoxModel::isMagnet()` |
| O revestimento envolvendo fundo e traseira (`Wrap_BottomBack`) | `ComponentRole::Wrap`, cobrado por área |

O ímã em si não é geometria: ele entra pela **lista de materiais** da
calculadora, cobrado por peça. É o que `BoxModel` já registrava —

> "A QUANTIDADE de ímãs não define a variação: ela vem da lista de materiais,
> onde o ímã é ferragem cobrada por peça. Três modelos que diferissem só no
> número de ímãs seriam o mesmo modelo três vezes."

## As três variações

O enum separa por CONSTRUÇÃO, não por número de ímãs:

- `rigid_magnet` — só a aba frontal
- `rigid_magnet_side` — mais duas abas laterais, que fecham o vão dos cantos
- `rigid_magnet_wrap` — a aba desce e ainda dobra sob o fundo

## Abrir

```
blender caixa-ima/caixa-ima.blend
```

Quadro 1 é a caixa fechada; 120, aberta. A cena roda a 24 fps.

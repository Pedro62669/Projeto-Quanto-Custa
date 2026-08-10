<?php

declare(strict_types=1);

namespace App\Services\Production;

/**
 * Um retângulo livre da chapa, e as duas sobras que ele gera ao ser ocupado.
 *
 * A árvore descreve cortes GUILHOTINÁVEIS: cada corte atravessa a peça de ponta
 * a ponta, que é o que uma guilhotina de cartonagem faz. Um empacotamento
 * melhor existe — nesting com recortes internos —, mas descreveria uma máquina
 * que a empresa não tem, e um plano de corte inexecutável é pior que nenhum.
 *
 * Vive fora do NestingCalculator e não pendurada no resultado. A versão original
 * deste algoritmo guardava a raiz no próprio objeto de saída
 * (`Object.defineProperty(layout, '_root', { enumerable: false })`): sobrevive
 * dentro de uma chamada, mas some no primeiro spread — e o estado do frontend é
 * Zustand, onde `{...layout}` é idioma. O dia em que alguém acrescentasse uma
 * peça a um layout já montado, a árvore não estaria mais lá.
 */
final class GuillotineNode
{
    private bool $ocupado = false;

    private ?self $direita = null;

    private ?self $abaixo = null;

    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $width,
        public readonly float $height,
    ) {}

    /**
     * Ocupa este retângulo com a peça, ou tenta nas sobras que ele já gerou.
     *
     * Devolve o nó onde a peça ficou (para ler x e y), ou null se não coube em
     * lugar nenhum desta subárvore. Só muta quando dá certo: uma tentativa
     * falhada deixa a árvore intacta, o que é o que permite testar a peça
     * girada logo em seguida sem desfazer nada.
     */
    public function insert(float $w, float $h, float $kerf): ?self
    {
        if ($this->ocupado) {
            return $this->direita?->insert($w, $h, $kerf)
                ?? $this->abaixo?->insert($w, $h, $kerf);
        }

        if ($w > $this->width || $h > $this->height) {
            return null;
        }

        $this->ocupado = true;

        /*
         * max(0, …) porque a lâmina pode comer mais do que sobra.
         *
         * Uma peça que ocupa a chapa inteira deixa `largura − w − kerf`
         * NEGATIVO. Sem o piso, nascem nós de dimensão negativa: eles nunca
         * aceitam peça (o teste acima recusa), mas fazem a escolha do corte
         * primário comparar dois números negativos, o que não significa nada.
         */
        $sobraLargura = max($this->width - $w - $kerf, 0.0);
        $sobraAltura = max($this->height - $h - $kerf, 0.0);

        /*
         * O corte primário vai no sentido da MAIOR sobra.
         *
         * É a heurística que mantém o maior retângulo livre inteiro em vez de
         * parti-lo em duas tiras estreitas — e retângulo inteiro é o que aceita
         * a próxima peça grande.
         */
        if ($sobraLargura > $sobraAltura) {
            // Corte vertical: a faixa à direita fica com a altura da peça, e a
            // de baixo atravessa a chapa inteira.
            $this->direita = new self($this->x + $w + $kerf, $this->y, $sobraLargura, $h);
            $this->abaixo = new self($this->x, $this->y + $h + $kerf, $this->width, $sobraAltura);
        } else {
            // Corte horizontal: a faixa à direita atravessa, e a de baixo fica
            // com a largura da peça.
            $this->direita = new self($this->x + $w + $kerf, $this->y, $sobraLargura, $this->height);
            $this->abaixo = new self($this->x, $this->y + $h + $kerf, $w, $sobraAltura);
        }

        return $this;
    }
}

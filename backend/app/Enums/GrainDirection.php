<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sentido da fibra do material.
 *
 * Papelão e papel não são isotrópicos: as fibras se alinham na direção em que a
 * folha saiu da máquina, e a chapa dobra fácil ao longo delas e resiste
 * atravessado. Cortar uma tampa com a fibra na direção errada produz uma peça
 * que empena depois de colada — e o defeito só aparece dias depois, quando a
 * caixa já foi entregue.
 *
 * Por isso o sentido é propriedade do MATERIAL e não da peça: quem tem fibra
 * tem sempre, em todas as peças cortadas dela. Tecido e alguns papéis de
 * revestimento não têm direção relevante, e para esses girar 90° é de graça.
 *
 * Quem consome: o NestingCalculator, para decidir se pode rotacionar uma peça ao
 * encaixá-la na chapa.
 */
enum GrainDirection: string
{
    /**
     * Sem direção relevante — a peça pode girar livremente no plano de corte.
     *
     * É o default, e é o default certo: o cadastro antigo não tem esta
     * informação, e assumir que TODO material tem fibra proibiria rotações
     * legítimas e pioraria o aproveitamento de quem usa tecido.
     */
    case None = 'none';

    /** Fibra paralela ao comprimento da folha. */
    case Length = 'length';

    /** Fibra paralela à largura da folha. */
    case Width = 'width';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sem fibra definida',
            self::Length => 'Fibra no comprimento',
            self::Width => 'Fibra na largura',
        };
    }

    /**
     * A peça pode ser girada 90° no plano de corte?
     *
     * Regra conservadora de propósito: qualquer fibra declarada trava a
     * rotação. Um modelo mais fino — comparar a fibra exigida pela peça com a
     * da folha — exigiria que cada peça declarasse a própria exigência, e
     * ninguém na bancada preenche isso peça a peça. Entre economizar 3% de
     * chapa e entregar uma caixa empenada, o sistema erra para o lado da caixa.
     */
    public function permiteRotacao(): bool
    {
        return $this === self::None;
    }
}

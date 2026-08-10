<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Unidade em que a matéria-prima é COMPRADA.
 * O motor de cálculo trabalha sempre em m²; kg exige gramatura para converter.
 */
enum MaterialUnit: string
{
    case SquareMeter = 'm2';
    case Kilogram = 'kg';

    /**
     * Peça: ferragem que se compra e se consome contada, não medida.
     *
     * Ímã, fecho, rebite, fita. Não tem gramatura nem área útil — o preço é por
     * unidade, e converter para R$/m² não significaria nada. É a razão de
     * `costPerSquareMeter()` recusar esta unidade em vez de inventar um número.
     */
    case Piece = 'un';

    /**
     * Metro cúbico: espuma e EVA, que se compram em BLOCO.
     *
     * Converter para m² exigiria saber a espessura da chapa — e o berço de
     * espuma justamente não é uma chapa: é um bloco com o nicho escavado. A
     * grandeza dele é volume, e forçá-lo em área daria um custo que muda
     * conforme a altura do nicho, que é o oposto do que acontece.
     */
    case CubicMeter = 'm3';

    public function requiresGrammage(): bool
    {
        return $this === self::Kilogram;
    }

    /** Unidades que o motor converte para R$/m². */
    public function isAreaBased(): bool
    {
        return $this === self::SquareMeter || $this === self::Kilogram;
    }

    /** Cobrado por volume — só espuma e EVA. */
    public function isVolumetric(): bool
    {
        return $this === self::CubicMeter;
    }

    public function label(): string
    {
        return match ($this) {
            self::SquareMeter => 'Metro quadrado',
            self::Kilogram => 'Quilo',
            self::Piece => 'Peça',
            self::CubicMeter => 'Metro cúbico (bloco)',
        };
    }
}

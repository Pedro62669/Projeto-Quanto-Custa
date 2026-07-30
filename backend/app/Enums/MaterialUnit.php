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

    public function requiresGrammage(): bool
    {
        return $this === self::Kilogram;
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialType: string
{
    case Cardboard = 'cardboard';
    case Paper = 'paper';
    case Fabric = 'fabric';
    case Plastic = 'plastic';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cardboard => 'Papelão',
            self::Paper => 'Papel',
            self::Fabric => 'Tecido',
            self::Plastic => 'Plástico',
            self::Other => 'Outro',
        };
    }
}

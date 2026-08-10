<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialType: string
{
    case Cardboard = 'cardboard';
    case Paper = 'paper';
    case Fabric = 'fabric';
    case Plastic = 'plastic';

    /** Ímã, fecho, rebite, fita — comprado e consumido por peça. */
    case Hardware = 'hardware';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cardboard => 'Papelão',
            self::Paper => 'Papel',
            self::Fabric => 'Tecido',
            self::Plastic => 'Plástico',
            self::Hardware => 'Ferragem',
            self::Other => 'Outro',
        };
    }
}

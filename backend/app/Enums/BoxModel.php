<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Modelos de embalagem suportados.
 *
 * Cada modelo tem uma planificação (blank) diferente, ou seja, uma fórmula
 * distinta para converter altura/largura/profundidade em área de material.
 * A fórmula vive em App\Services\Pricing\BlankCalculator — este enum apenas
 * nomeia o modelo e descreve seu comportamento para a UI.
 */
enum BoxModel: string
{
    /** Caixa maleta / americana (Regular Slotted Container) — 4 abas em cima e embaixo. */
    case Rsc = 'rsc';

    /** Bandeja / caixa com tampa solta (base + tampa telescópica). */
    case Tray = 'tray';

    /** Luva ou cinta que envolve o produto (sem tampo nem fundo). */
    case Sleeve = 'sleeve';

    /** Saco / envelope: duas faces planas seladas nas bordas. */
    case Pouch = 'pouch';

    public function label(): string
    {
        return match ($this) {
            self::Rsc => 'Caixa americana (RSC)',
            self::Tray => 'Caixa com tampa',
            self::Sleeve => 'Luva / cinta',
            self::Pouch => 'Saco / envelope',
        };
    }

    /**
     * Minutos de produção sugeridos por unidade — serve apenas como valor
     * inicial do formulário; o usuário pode sobrescrever.
     */
    public function defaultProductionMinutes(): float
    {
        return match ($this) {
            self::Rsc => 2.5,
            self::Tray => 4.0,
            self::Sleeve => 1.2,
            self::Pouch => 1.0,
        };
    }
}

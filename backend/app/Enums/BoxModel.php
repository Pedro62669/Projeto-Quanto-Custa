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

    /**
     * Tubo / lata cilíndrica: corpo enrolado, fundo em disco e tampa.
     *
     * Um cilindro não tem largura e profundidade independentes — tem
     * diâmetro. O modelo reaproveita `width_mm` como diâmetro, e a
     * profundidade é ignorada: para um cilindro, ambas SÃO o diâmetro
     * (é literalmente a caixa envolvente da peça), então não há distorção
     * semântica em gravar as duas iguais.
     */
    case Tube = 'tube';

    /**
     * Caixa gaveta (tipo fósforo): luva externa + gaveta que desliza dentro.
     *
     * São duas peças independentes. As dimensões informadas são as INTERNAS
     * da gaveta — o espaço útil —, e a luva é dimensionada em volta dela,
     * vencendo a espessura das paredes e a folga de deslize.
     */
    case Drawer = 'drawer';

    /**
     * Mailer box (RETT — roll end tuck top): a caixa de e-commerce.
     *
     * Uma chapa só, cortada em faca e dobrada sem cola: fundo, paredes e
     * tampa saem da mesma peça. A tampa é ARTICULADA na parede traseira — não
     * é peça separada — e fecha por uma banda frontal com lingueta que entra
     * na caixa.
     *
     * O que define o custo é o ROLO: as laterais e a parede frontal sobem,
     * dobram 180° no topo e descem por dentro, formando parede dupla de canto
     * liso. É daí que vem a rigidez do modelo — e o motivo de uma mailer
     * consumir bem mais material que um RSC de mesma medida.
     */
    case Mailer = 'mailer';

    public function label(): string
    {
        return match ($this) {
            self::Rsc => 'Caixa americana (RSC)',
            self::Tray => 'Caixa com tampa',
            self::Sleeve => 'Luva / cinta',
            self::Pouch => 'Saco / envelope',
            self::Tube => 'Tubo / lata cilíndrica',
            self::Drawer => 'Caixa gaveta',
            self::Mailer => 'Mailer box (e-commerce)',
        };
    }

    /**
     * Modelos cuja seção é circular.
     *
     * Quem consome: a UI (renomeia "Largura" para "Diâmetro" e esconde a
     * profundidade) e o motor (usa a largura como diâmetro).
     */
    public function isCylindrical(): bool
    {
        return $this === self::Tube;
    }

    /** Modelos que têm uma tampa como peça separada. */
    public function hasSeparateLid(): bool
    {
        return in_array($this, [self::Tray, self::Tube], true);
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
            self::Tube => 3.0,
            // Duas peças montadas separadamente: leva mais tempo que um RSC.
            self::Drawer => 4.5,
            // Sai da faca pronta: dobra e trava, sem cola nem fita. É o
            // modelo mais rápido de montar entre as caixas rígidas.
            self::Mailer => 2.0,
        };
    }
}

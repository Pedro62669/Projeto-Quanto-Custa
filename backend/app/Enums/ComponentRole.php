<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * O papel que um material cumpre dentro da peça.
 *
 * Cartonagem rígida não é feita de UM material. Uma caixa tampa solta tem
 * papelão cinza dando estrutura, papel de revestimento cobrindo por fora e
 * virando para dentro, e — nos modelos com fecho — ímãs embutidos. Os três
 * custam de formas diferentes: os dois primeiros por área, o terceiro por peça.
 *
 * O papel também decide QUAL ÁREA o componente consome: o revestimento sempre
 * precisa de mais chapa que o cinza que ele cobre, porque vira sobre as bordas.
 */
enum ComponentRole: string
{
    /** Papelão cinza: a estrutura. Consome a área do blank. */
    case Structure = 'structure';

    /**
     * Papel de revestimento: cobre o cinza e vira para dentro.
     *
     * Consome a área do blank ACRESCIDA da virada em todas as bordas — é o
     * único papel cuja área difere da estrutura, e ignorar a diferença
     * subestima o material mais caro da peça.
     */
    case Wrap = 'wrap';

    /**
     * Ferragem: ímã, fecho, fita de cetim, rebite.
     *
     * Cobrada por PEÇA, não por área. Um ímã de 6×2mm tem área irrelevante e
     * preço que não é irrelevante — quatro deles podem custar mais que o papel
     * da caixa inteira.
     */
    case Hardware = 'hardware';

    /**
     * Berço de acomodação: espuma, nichos, divisórias.
     *
     * A grandeza que ele consome depende do TIPO do berço, não do papel: espuma
     * é volume, cartonagem é área. Por isso este é o único papel cujo cálculo
     * precisa de parâmetros próprios (a grade, a altura) além do material.
     */
    case Cradle = 'cradle';

    public function label(): string
    {
        return match ($this) {
            self::Structure => 'Estrutura (papelão cinza)',
            self::Wrap => 'Revestimento',
            self::Hardware => 'Ferragem (ímãs, fechos)',
            self::Cradle => 'Berço de acomodação',
        };
    }

    /** Cobrado por área (m²) ou por peça? */
    public function isAreaBased(): bool
    {
        return $this === self::Structure || $this === self::Wrap;
    }
}

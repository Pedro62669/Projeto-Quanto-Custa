<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Berços de acomodação — o que segura o produto dentro da caixa.
 *
 * Cinco construções que resolvem o mesmo problema com custos muito diferentes.
 * O berço é o item que o cartonageiro mais esquece de cobrar: ele não aparece
 * na foto da caixa fechada, mas pode dobrar o consumo de papelão e o tempo de
 * montagem de uma peça.
 *
 * Cada tipo decide DUAS coisas: qual grandeza o material consome (volume ou
 * área) e quanto tempo de produção acrescenta.
 */
enum CradleType: string
{
    /**
     * Bloco de espuma ou EVA com o nicho recortado.
     *
     * Único tipo cobrado por VOLUME: espuma se compra em bloco (R$/m³), não em
     * chapa. O recorte não desconta nada — o vazio sai do bloco que já foi
     * pago, e o miolo removido vira sobra.
     */
    case Foam = 'foam';

    /** Nichos de cartonagem rígida revestida — divisórias suspensas. */
    case BoardNiche = 'board_niche';

    /** Base em cartonagem com colmeia interna de papel cartão. */
    case PaperNiche = 'paper_niche';

    /** Peça única de papel dobrado — o berço mais leve e barato. */
    case PaperFold = 'paper_fold';

    /**
     * Grade de divisórias encaixadas (linhas × colunas).
     *
     * Tiras cruzadas com ranhuras fêmea-fêmea: cada uma vai até a metade da
     * altura, e o encaixe as trava sem cola. O consumo depende da GRADE, não
     * das medidas da caixa — e é o único tipo cujo custo cresce em degraus.
     */
    case DividerGrid = 'divider_grid';

    public function label(): string
    {
        return match ($this) {
            self::Foam => 'Berço em espuma / EVA',
            self::BoardNiche => 'Nichos em cartonagem revestida',
            self::PaperNiche => 'Nichos em papel cartão',
            self::PaperFold => 'Berço de papel dobrado',
            self::DividerGrid => 'Divisórias parametrizadas',
        };
    }

    /** Cobrado por volume (m³) em vez de área (m²)? */
    public function isVolumetric(): bool
    {
        return $this === self::Foam;
    }

    /** Precisa da grade linhas × colunas para ser calculado? */
    public function needsGrid(): bool
    {
        return $this === self::DividerGrid;
    }

    /**
     * Minutos de montagem acrescidos por peça.
     *
     * A espuma é a mais cara em material e a mais BARATA em tempo: sai pronta
     * do corte a laser e só é encaixada. Os nichos revestidos são o inverso —
     * cada divisória é cortada, revestida e colada à mão.
     */
    public function extraProductionMinutes(): float
    {
        return match ($this) {
            self::Foam => 1.5,
            self::BoardNiche => 8.0,
            self::PaperNiche => 4.5,
            self::PaperFold => 2.0,
            self::DividerGrid => 3.5,
        };
    }
}

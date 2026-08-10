<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fator de eficiência: quanto da hora paga vira hora produtiva.
 *
 * Ninguém produz oito horas em oito horas. Há troca de ferramenta, ajuste de
 * máquina, atendimento ao cliente, café, retrabalho. O fator reconhece isso e
 * é o parâmetro que mais move a hora-empresa — porque ele mexe no DIVISOR: a
 * mesma despesa dividida por menos horas produtivas encarece cada minuto.
 *
 * Os três cenários são fixos e aparecem lado a lado de propósito. Quem escolhe
 * 100% quase sempre não sabe que está escolhendo; ver os três juntos transforma
 * a escolha num ato consciente, e mostra que o otimismo tem preço — literal.
 */
enum EfficiencyScenario: int
{
    case Optimistic = 100;
    case Recommended = 85;
    case Conservative = 75;

    public function label(): string
    {
        return match ($this) {
            self::Optimistic => 'Sem eficiência (otimista)',
            self::Recommended => 'Recomendado (equilibrado)',
            self::Conservative => 'Conservador (muitos imprevistos)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Optimistic => 'Considera que toda hora paga é hora produzida. Subestima o custo.',
            self::Recommended => 'Reserva 15% para setup, ajustes e imprevistos do dia a dia.',
            self::Conservative => 'Para operações com muita troca de pedido, retrabalho ou atendimento.',
        };
    }

    /** O fator como multiplicador: 85 => 0.85. */
    public function factor(): float
    {
        return $this->value / 100;
    }

    /**
     * A matriz de comparação, na ordem em que a interface exibe.
     *
     * Do otimista ao conservador: a leitura de cima para baixo mostra o custo
     * SUBINDO conforme a estimativa fica realista, que é a lição do painel.
     *
     * @return list<self>
     */
    public static function comparison(): array
    {
        return [self::Optimistic, self::Recommended, self::Conservative];
    }
}

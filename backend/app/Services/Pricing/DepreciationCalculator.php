<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Equipment;

/**
 * Diluição da depreciação do parque de máquinas no custo de cada peça.
 *
 * A conta é uma divisão, mas a ideia por trás dela decide o preço: o parque
 * consome um valor fixo por mês, independente de quantas caixas saírem. Quem
 * produz 5.000 peças rateia esse valor por 5.000; quem produz 500 rateia pelo
 * mesmo total dividido por 500 — e paga dez vezes mais de depreciação em cada
 * peça. É por isso que a produção mensal ENTRA na fórmula em vez de ser
 * estimada: ela é a variável que o usuário controla e a que mais move o
 * resultado.
 *
 * Fica em Services\Pricing, junto de BlankCalculator e PricingEngine, porque é
 * mais um componente de custo — não um relatório sobre o inventário.
 */
class DepreciationCalculator
{
    /**
     * Soma da depreciação mensal de todo o parque da empresa.
     *
     * A query é escopada pelo TenantScope: soma o parque de QUEM ESTÁ LOGADO,
     * sem que este service precise saber que existe multi-inquilino.
     *
     * A soma acontece em PHP, e não com SUM() no banco, de propósito: a parcela
     * de cada máquina é arredondada em duas casas antes de entrar no total (ver
     * Equipment::getMonthlyDepreciationAttribute). Somar no SQL produziria um
     * total que não bate com a soma das linhas que o usuário vê na tela — e
     * "conferi na mão e não fecha" destrói a confiança no preço.
     */
    public function monthlyTotal(): float
    {
        return round(
            Equipment::query()->get()->sum(
                fn (Equipment $equipment): float => $equipment->monthly_depreciation
            ),
            2,
        );
    }

    /**
     * Quanto a depreciação acrescenta ao custo de UMA peça.
     *
     * @param  float  $monthlyProduction  Produção mensal atual, em unidades.
     *
     * @throws \DomainException quando a produção informada é zero ou negativa.
     */
    public function perUnit(float $monthlyProduction): float
    {
        if ($monthlyProduction <= 0) {
            /*
             * Exceção, e não zero.
             *
             * Produção zero não significa "sem depreciação" — significa que a
             * pergunta não tem resposta: o custo fixo continua correndo e não há
             * peça para absorvê-lo, então o custo por unidade tende ao infinito.
             * Devolver 0.0 seria a mentira mais cara do motor, escondendo o
             * custo justamente no cenário em que ele mais pesa.
             *
             * DomainException vira 422 com a mensagem visível — ver
             * bootstrap/app.php.
             */
            throw new \DomainException(
                'Informe a produção mensal estimada (em unidades) para ratear a depreciação das máquinas.'
            );
        }

        // 4 casas, como todo valor unitário do motor: uma embalagem pode custar
        // R$ 0,0842 e ser multiplicada por 50.000 — arredondar para centavos
        // aqui distorceria o total em centenas de reais. Ver PricingEngine::money().
        return round($this->monthlyTotal() / $monthlyProduction, 4);
    }

    /**
     * O impacto completo, pronto para exibição.
     *
     * Devolve o total mensal junto do custo unitário porque a tela precisa dos
     * dois para explicar o número: "R$ 850,00/mês ÷ 5.000 peças = R$ 0,1700 por
     * peça" é auditável; "R$ 0,1700" sozinho é um número que o usuário aceita
     * ou rejeita sem entender.
     *
     * @return array{monthly_total: float, monthly_production: float, cost_per_unit: float, equipment_count: int}
     */
    public function impact(float $monthlyProduction): array
    {
        return [
            'monthly_total' => $this->monthlyTotal(),
            'monthly_production' => $monthlyProduction,
            'cost_per_unit' => $this->perUnit($monthlyProduction),
            'equipment_count' => Equipment::query()->count(),
        ];
    }
}

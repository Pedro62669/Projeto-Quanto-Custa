<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * Motor de precificação — única fonte de verdade dos números do sistema.
 *
 * Regras que este serviço respeita:
 *  1. É PURO: não toca em HTTP, request, sessão ou banco. Recebe PricingInput,
 *     devolve PricingResult. Isso o torna testável em unidade sem infra.
 *  2. Não persiste nada. Quem grava é o Controller.
 *  3. Tem gêmeo idêntico em TypeScript (frontend/lib/pricing/engine.ts) para o
 *     preview em tempo real. Qualquer alteração aqui DEVE ser espelhada lá —
 *     e a versão do motor é gravada no snapshot de cada orçamento.
 *
 * Cadeia de cálculo:
 *
 *   área líquida ─(+desperdício)→ área bruta ─(×R$/m²)→ custo material
 *   tempo ─→ mão de obra + hora-máquina + energia
 *   custo direto ─(+rateio %)→ CMV ─(+margem)→ preço ─(+impostos)→ preço final
 */
final class PricingEngine
{
    /** Gravada no snapshot: permite explicar por que um orçamento antigo tem outro número. */
    public const VERSION = '1.0.0';

    private const MINUTES_PER_HOUR = 60.0;

    public function calculate(PricingInput $input): PricingResult
    {
        $material = $input->material;
        $settings = $input->settings;

        // ── 1. Geometria: quanto material a peça consome ────────────────────
        $blankCalculator = new BlankCalculator(thicknessMm: $material->thickness_mm ?? 0.0);

        $blank = $blankCalculator->blankDimensions(
            $input->boxModel,
            $input->widthMm,
            $input->heightMm,
            $input->depthMm,
        );

        $netAreaPerUnit = ($blank['width'] * $blank['height']) / 1_000_000.0;

        // Desperdício: aparas, refile e perdas de setup. Incide sobre a área,
        // não sobre o custo — mantém o número interpretável em m².
        $grossAreaPerUnit = $netAreaPerUnit * (1 + $input->wastePercent / 100);
        $grossAreaTotal = $grossAreaPerUnit * $input->quantity;

        // ── 2. Custo da matéria-prima ───────────────────────────────────────
        // costPerSquareMeter() normaliza materiais cotados em kg via gramatura.
        $materialCost = $grossAreaPerUnit * $material->costPerSquareMeter();

        // ── 3. Mão de obra e operacional ────────────────────────────────────
        $hours = $input->productionMinutesPerUnit / self::MINUTES_PER_HOUR;

        $laborCost = $hours * $settings->labor_hour_rate;
        $machineCost = $hours * $settings->machine_hour_rate;

        // Energia = horas × kW × R$/kWh. Rateia o consumo real do parque em vez
        // de embutir um valor cego na hora-máquina.
        $energyCost = $hours * $settings->machine_power_kw * $settings->energy_tariff_per_kwh;

        // ── 4. CMV (custo da mercadoria vendida) ────────────────────────────
        $directCost = $materialCost + $laborCost + $machineCost + $energyCost;
        $overheadCost = $directCost * ($settings->overhead_percent / 100);
        $unitCost = $directCost + $overheadCost;

        // ── 5. Precificação ─────────────────────────────────────────────────
        $unitPrice = $this->applyProfitAndTax(
            $unitCost,
            $input->profitMarginPercent,
            $input->pricingMode,
            $settings->tax_percent,
        );

        // ── 6. Totais ───────────────────────────────────────────────────────
        $unitCost = $this->money($unitCost);
        $unitPrice = $this->money($unitPrice);

        $totalCost = $this->money($unitCost * $input->quantity, 2);
        $totalPrice = $this->money($unitPrice * $input->quantity, 2);
        $taxAmount = $this->money($totalPrice * ($settings->tax_percent / 100), 2);

        // Lucro real = preço − custo − imposto.
        $profitAmount = $this->money($totalPrice - $totalCost - $taxAmount, 2);

        return new PricingResult(
            areaM2PerUnit: round($netAreaPerUnit, 6),
            areaM2Total: round($grossAreaTotal, 6),
            blankWidthMm: round($blank['width'], 2),
            blankHeightMm: round($blank['height'], 2),

            materialCost: $this->money($materialCost),
            laborCost: $this->money($laborCost),
            machineCost: $this->money($machineCost),
            energyCost: $this->money($energyCost),
            overheadCost: $this->money($overheadCost),
            unitCost: $unitCost,

            unitPrice: $unitPrice,
            totalCost: $totalCost,
            totalPrice: $totalPrice,
            profitAmount: $profitAmount,
            taxAmount: $taxAmount,

            effectiveMarginPercent: $totalPrice > 0
                ? round($profitAmount / $totalPrice * 100, 2)
                : 0.0,
        );
    }

    /**
     * Converte o CMV em preço de venda, aplicando lucro e impostos.
     *
     * Os dois modos tratam o imposto de formas diferentes, e a diferença é a
     * razão de existir este método único em vez de dois passos encadeados:
     *
     * ── markup: preço = custo × (1 + m), depois embute o imposto ───────────
     *   "Acrescente 30% sobre o custo." Não promete margem nenhuma sobre a
     *   venda: com custo 100 e 30% de markup, o preço é 130 e a margem real
     *   fica em 23,1% (menos ainda depois do imposto).
     *
     * ── margin: preço = custo ÷ (1 − m − imposto) ──────────────────────────
     *   "Quero 30% líquidos sobre o preço de venda." Aqui está o ponto sutil:
     *   margem e impostos precisam sair do MESMO divisor, porque ambos são
     *   fatias do preço final. Aplicá-los em sequência — custo ÷ (1−m) e
     *   depois ÷ (1−imposto) — parece equivalente e não é: com 30% de margem
     *   e 8% de imposto, a margem real cairia para 27,6%, quebrando a promessa
     *   que a interface faz ao usuário. É o markup divisor clássico.
     */
    private function applyProfitAndTax(
        float $cost,
        float $percent,
        string $mode,
        float $taxPercent,
    ): float {
        $tax = max($taxPercent, 0.0);

        if ($mode === 'margin') {
            $divisor = 1 - ($percent + $tax) / 100;

            // Margem + imposto >= 100% do preço não sobra nada para o custo:
            // não existe preço finito que satisfaça a equação.
            if ($divisor <= 0) {
                throw new \DomainException(sprintf(
                    'A soma da margem (%.2f%%) com os impostos (%.2f%%) deve ser menor que 100%%. '
                    .'Reduza a margem ou use o modo "markup".',
                    $percent,
                    $tax,
                ));
            }

            return $cost / $divisor;
        }

        $price = $cost * (1 + $percent / 100);

        if ($tax <= 0) {
            return $price;
        }

        if ($tax >= 100.0) {
            throw new \DomainException('A alíquota de impostos deve ser menor que 100%.');
        }

        // Imposto "por dentro": o preço precisa ser tal que, após recolher o
        // tributo, ainda reste o valor pré-imposto. Somar a alíquota sobre o
        // preço deixaria a empresa no vermelho justamente pelo valor do imposto.
        return $price / (1 - $tax / 100);
    }

    /**
     * Arredonda valores monetários.
     *
     * Valores unitários usam 4 casas porque uma embalagem pode custar R$ 0,0842
     * e multiplicar por 50.000 unidades — arredondar para centavos aqui
     * distorceria o total em centenas de reais.
     */
    private function money(float $value, int $precision = 4): float
    {
        return round($value, $precision);
    }
}

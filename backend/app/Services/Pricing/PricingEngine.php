<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\CradleType;

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
    /**
     * Gravada no snapshot: permite explicar por que um orçamento antigo tem
     * outro número.
     *
     * 1.1.0 — modo hora-empresa. A minor e não uma major porque o
     * comportamento de toda configuração existente é bit a bit o mesmo: o
     * regime novo só entra com `use_company_hour` ligado, que nasce falso.
     *
     * 1.2.0 — lista de materiais: revestimento e ferragem. Também minor: sem
     * componentes na entrada, os dois custos saem zero e o preço não se move.
     *
     * 1.3.0 — berços de acomodação. Minor pela mesma razão: sem berço na
     * entrada, o custo e os minutos extras saem zero.
     */
    public const VERSION = '1.3.0';

    private const MINUTES_PER_HOUR = 60.0;

    public function calculate(PricingInput $input): PricingResult
    {
        $material = $input->material;
        $settings = $input->settings;

        // ── 1. Geometria: quanto material a peça consome ────────────────────
        $blankCalculator = new BlankCalculator(thicknessMm: $material->thickness_mm ?? 0.0);

        /*
         * A tampa é resolvida ANTES do plano de corte, e de propósito: o
         * consumo de material precisa refletir a tampa REAL. Calcular o blank
         * com a tampa sugerida faria uma tampa mais alta sair de graça.
         */
        $lid = $blankCalculator->resolveLidDimensions(
            $input->boxModel,
            $input->widthMm,
            $input->heightMm,
            $input->depthMm,
            [
                'width' => $input->lidWidthMm,
                'depth' => $input->lidDepthMm,
                'height' => $input->lidHeightMm,
            ],
        );

        $blank = $blankCalculator->blankDimensions(
            $input->boxModel,
            $input->widthMm,
            $input->heightMm,
            $input->depthMm,
            $lid,
        );

        $netAreaPerUnit = ($blank['width'] * $blank['height']) / 1_000_000.0;

        // Desperdício: aparas, refile e perdas de setup. Incide sobre a área,
        // não sobre o custo — mantém o número interpretável em m².
        $grossAreaPerUnit = $netAreaPerUnit * (1 + $input->wastePercent / 100);
        $grossAreaTotal = $grossAreaPerUnit * $input->quantity;

        // ── 2. Custo da matéria-prima ───────────────────────────────────────
        // costPerSquareMeter() normaliza materiais cotados em kg via gramatura.
        $materialCost = $grossAreaPerUnit * $material->costPerSquareMeter();

        /*
         * Revestimento: a segunda área da cartonagem rígida.
         *
         * O papel cobre o cinza e vira sobre as bordas, então consome MAIS
         * chapa que a estrutura. E custa mais por m² — revestimento bom passa
         * de R$ 20 onde o cinza fica em R$ 5. Tratar os dois como um material
         * só subestimaria justamente o mais caro dos dois.
         *
         * O desperdício incide igual: a mesma guilhotina, as mesmas aparas.
         */
        $netWrapAreaPerUnit = $blankCalculator->wrapAreaInSquareMeters(
            $input->boxModel,
            $input->widthMm,
            $input->heightMm,
            $input->depthMm,
            $lid,
        );

        $grossWrapAreaPerUnit = $netWrapAreaPerUnit * (1 + $input->wastePercent / 100);

        $wrapCost = $input->wrapCostPerM2 === null
            ? 0.0
            : $grossWrapAreaPerUnit * $input->wrapCostPerM2;

        /*
         * Ferragem: contada, não medida.
         *
         * Sem desperdício percentual em cima — ímã não tem apara. Quem quebra
         * um na montagem lança a perda na quantidade, que é onde ela é visível
         * e discutível, em vez de diluída num percentual de refile.
         */
        $hardwareCost = 0.0;

        foreach ($input->hardware as $item) {
            $hardwareCost += $item['cost_per_piece'] * $item['quantity'];
        }

        /*
         * Berço de acomodação.
         *
         * Consome numa grandeza que depende do TIPO: espuma é volume (bloco
         * escavado), cartonagem e papel são área. O desperdício incide nos dois
         * — a espuma também tem apara de corte, e ignorá-la faria o berço mais
         * caro do catálogo parecer o único sem perda.
         *
         * O tempo extra é somado à jornada da peça, e não escondido: montar
         * nichos revestidos custa oito minutos que ninguém lembra de lançar.
         */
        $cradleCost = 0.0;
        $cradleMinutes = 0.0;
        $cradleAreaPerUnit = 0.0;
        $cradleVolumePerUnit = 0.0;

        if ($input->cradle !== null) {
            $cradleType = CradleType::from($input->cradle['type']);

            $consumo = (new CradleCalculator)->consumption(
                type: $cradleType,
                widthMm: $input->widthMm,
                heightMm: $input->heightMm,
                depthMm: $input->depthMm,
                rows: $input->cradle['rows'],
                columns: $input->cradle['columns'],
                heightRatio: $input->cradle['height_ratio'],
                stripThicknessMm: $input->cradle['strip_thickness_mm'],
            );

            $cradleAreaPerUnit = $consumo['area_m2'];
            $cradleVolumePerUnit = $consumo['volume_m3'];

            $grandeza = $cradleType->isVolumetric() ? $cradleVolumePerUnit : $cradleAreaPerUnit;

            $cradleCost = $grandeza * (1 + $input->wastePercent / 100)
                * $input->cradle['cost_per_unit'];

            $cradleMinutes = $cradleType->extraProductionMinutes();
        }

        // ── 3. Mão de obra e operacional ────────────────────────────────────
        //
        // O tempo do berço entra na jornada da peça: ele é trabalho real, e
        // deixá-lo de fora faria a mão de obra da caixa com nichos custar o
        // mesmo que a de uma caixa vazia.
        $hours = ($input->productionMinutesPerUnit + $cradleMinutes) / self::MINUTES_PER_HOUR;

        /*
         * Dois regimes de custo indireto, e nunca os dois ao mesmo tempo.
         *
         * ESTIMATIVA (modo desligado, comportamento histórico): a mão de obra
         * sai de um R$/h digitado e os indiretos de um percentual sobre o custo
         * direto. Dois palpites.
         *
         * HORA-EMPRESA (modo ligado): o minuto já carrega a despesa fixa real
         * da empresa — aluguel, contador, energia, pró-labore e, opcionalmente,
         * a depreciação do parque — rateada pelas horas que de fato produzem.
         *
         * Por que o rateio percentual ZERA no segundo regime: ele existe para
         * cobrar exatamente as mesmas despesas. Mantê-lo somaria aluguel sobre
         * aluguel, e o erro cresceria junto com o tempo de produção da peça —
         * silencioso justamente nos pedidos maiores.
         */
        if ($input->companyMinuteCost !== null) {
            $laborCost = ($input->productionMinutesPerUnit + $cradleMinutes)
                * $input->companyMinuteCost;
        } else {
            $laborCost = $hours * $settings->labor_hour_rate;
        }

        /*
         * A hora-máquina permanece nos dois regimes, mas MUDA DE SIGNIFICADO.
         *
         * Ela foi definida como "depreciação + manutenção". Com o modo ligado e
         * `company_includes_depreciation`, a depreciação já entrou pela
         * hora-empresa, e este campo passa a valer manutenção apenas. O motor
         * não tem como separar as duas parcelas de um número só — quem publica
         * a configuração precisa ajustá-lo, e é o que a validação do
         * CostSettingController avisa.
         */
        $machineCost = $hours * $settings->machine_hour_rate;

        // Energia = horas × kW × R$/kWh. Rateia o consumo real do parque em vez
        // de embutir um valor cego na hora-máquina.
        $energyCost = $hours * $settings->machine_power_kw * $settings->energy_tariff_per_kwh;

        // ── 4. CMV (custo da mercadoria vendida) ────────────────────────────
        $directCost = $materialCost + $wrapCost + $hardwareCost + $cradleCost
            + $laborCost + $machineCost + $energyCost;

        $overheadCost = $input->companyMinuteCost !== null
            ? 0.0
            : $directCost * ($settings->overhead_percent / 100);

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
            wrapAreaM2PerUnit: round($netWrapAreaPerUnit, 6),
            cradleAreaM2PerUnit: round($cradleAreaPerUnit, 6),
            cradleVolumeM3PerUnit: round($cradleVolumePerUnit, 9),

            lidWidthMm: $lid ? round($lid['width'], 2) : null,
            lidDepthMm: $lid ? round($lid['depth'], 2) : null,
            lidHeightMm: $lid ? round($lid['height'], 2) : null,

            materialCost: $this->money($materialCost),
            wrapCost: $this->money($wrapCost),
            hardwareCost: $this->money($hardwareCost),
            cradleCost: $this->money($cradleCost),
            cradleMinutes: round($cradleMinutes, 2),
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

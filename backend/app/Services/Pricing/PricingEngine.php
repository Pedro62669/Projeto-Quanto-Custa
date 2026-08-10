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
     *
     * 1.4.0 — modelo livre. Ainda minor, e vale explicar por quê: o ramo novo
     * só é alcançado por `box_model: free`, um valor que não existia antes. Todo
     * orçamento já gravado escolheu outro modelo, e para esses o caminho é o
     * mesmo bit a bit — o BlankCalculator continua sendo quem responde.
     *
     * 1.5.0 — frações de insumo e de mão de obra sobre o preço. Minor porque
     * nenhum valor existente se move: são dois campos NOVOS, derivados dos que
     * já estavam no resultado.
     *
     * Repare no que NÃO mudou de versão junto: o custo por lote com frete
     * rateado. Ele altera o R$/m² de um material, mas é resolvido pelo
     * controller ANTES de o motor rodar — o motor recebe o número pronto, como
     * sempre recebeu. Cadastrar um lote muda o preço da caixa sem mudar uma
     * linha do cálculo, e é essa fronteira que mantém a paridade possível.
     */
    public const VERSION = '1.5.0';

    private const MINUTES_PER_HOUR = 60.0;

    public function calculate(PricingInput $input): PricingResult
    {
        $material = $input->material;
        $settings = $input->settings;

        // ── 1. Geometria: quanto material a peça consome ────────────────────
        if ($input->boxModel->isFree()) {
            /*
             * Modelo livre: não há equação a aplicar.
             *
             * Todos os outros modelos derivam a planificação de largura, altura
             * e profundidade. Aqui a construção é desconhecida — é a caixa que o
             * cliente desenhou — e quem mede é o usuário. O motor apenas soma o
             * que ele mediu.
             *
             * O BlankCalculator inteiro fica de fora, e é isso que torna o
             * modelo honesto: forçar uma peça fora do catálogo no molde "mais
             * parecido" produziria uma área que não corresponde ao que vai ser
             * cortado, e um preço plausível e errado.
             */
            $consumo = $this->customPartsConsumption($input->customParts);

            $lid = null;

            /*
             * Não existe UM blank: existem N retângulos. Zero aqui é a resposta
             * honesta, e é o que a ficha técnica lê para saber que precisa
             * listar as peças em vez de desenhar uma planificação.
             */
            $blank = ['width' => 0.0, 'height' => 0.0];

            $netAreaPerUnit = $consumo['structure_net_m2'];
            $grossAreaPerUnit = $consumo['structure_gross_m2'];
            $materialCost = $consumo['structure_cost'];

            $netWrapAreaPerUnit = $consumo['wrap_net_m2'];
            $grossWrapAreaPerUnit = $consumo['wrap_gross_m2'];
            $wrapCost = $consumo['wrap_cost'];
        } else {
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

            // ── 2. Custo da matéria-prima ───────────────────────────────────
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
        }

        $grossAreaTotal = $grossAreaPerUnit * $input->quantity;

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

            /*
             * Frações sobre o PREÇO, não sobre o custo.
             *
             * Sobre o custo elas sempre somariam 100% e não diriam nada. Sobre o
             * preço respondem a pergunta que o dono da cartonagem realmente faz:
             * "estou vendendo papelão ou vendendo trabalho?".
             *
             * O guarda de preço zero não é teórico: margem zero com custo zero
             * (peça mínima, tempo zero) é um caminho que a suíte exercita, e uma
             * divisão por zero aqui derrubaria o cálculo inteiro por causa de um
             * número que é só informativo.
             */
            materialSharePercent: $unitPrice > 0
                ? round(($materialCost + $wrapCost + $hardwareCost + $cradleCost) / $unitPrice * 100, 2)
                : 0.0,

            laborSharePercent: $unitPrice > 0
                ? round($laborCost / $unitPrice * 100, 2)
                : 0.0,
        );
    }

    /**
     * Soma as peças medidas à mão do modelo livre.
     *
     * Separa por papel do componente porque estrutura e revestimento são LINHAS
     * DIFERENTES da ficha de custo — o cinza e o papel de capa têm preços que
     * diferem por quatro vezes, e uma soma única esconderia qual dos dois puxou
     * o custo para cima.
     *
     * Cada peça aplica a PRÓPRIA perda. É a diferença mais importante em
     * relação aos modelos com equação, onde um percentual único vale para tudo:
     * aqui o usuário mistura papelão (12%), kraft (8%) e tecido (15%) no mesmo
     * orçamento, e um número só trataria os três igual.
     *
     * @param  list<array{
     *     role: string,
     *     cost_per_m2: float,
     *     waste_percent: float,
     *     width_mm: float,
     *     length_mm: float,
     *     quantity: int
     * }>  $parts
     * @return array{
     *     structure_net_m2: float, structure_gross_m2: float, structure_cost: float,
     *     wrap_net_m2: float, wrap_gross_m2: float, wrap_cost: float
     * }
     */
    private function customPartsConsumption(array $parts): array
    {
        if ($parts === []) {
            /*
             * Sem peça não há caixa. Deixar passar produziria um orçamento com
             * material zero e só mão de obra — um preço que parece calculado e
             * não descreve nada. DomainException vira 422 com a mensagem na
             * tela; ver o mapeamento em bootstrap/app.php.
             */
            throw new \DomainException(
                'O modelo livre precisa de ao menos uma peça. '
                .'Informe as chapas e folhas que serão cortadas, com medida e quantidade.'
            );
        }

        $totais = [
            'structure_net_m2' => 0.0, 'structure_gross_m2' => 0.0, 'structure_cost' => 0.0,
            'wrap_net_m2' => 0.0, 'wrap_gross_m2' => 0.0, 'wrap_cost' => 0.0,
        ];

        foreach ($parts as $part) {
            // Quantidade é POR CAIXA — a multiplicação pelo lote acontece
            // depois, junto com todo o resto do orçamento.
            $liquida = ($part['width_mm'] * $part['length_mm']) / 1_000_000.0 * $part['quantity'];
            $bruta = $liquida * (1 + $part['waste_percent'] / 100);

            $prefixo = $part['role'] === 'wrap' ? 'wrap' : 'structure';

            $totais["{$prefixo}_net_m2"] += $liquida;
            $totais["{$prefixo}_gross_m2"] += $bruta;
            $totais["{$prefixo}_cost"] += $bruta * $part['cost_per_m2'];
        }

        return $totais;
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

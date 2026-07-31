<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BoxModel;
use App\Enums\MaterialUnit;
use App\Models\CostSetting;
use App\Models\Material;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\PricingInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Testes do motor de precificação.
 *
 * Estende PHPUnit\TestCase (e não o TestCase do Laravel): o motor é puro, e
 * os models são instanciados sem persistir. Sem banco, sem container, sem
 * bootstrap do framework — se estes testes ficarem lentos ou precisarem de
 * infra, é sinal de que a regra de negócio vazou para fora do serviço.
 *
 * Os valores esperados foram calculados à mão e estão documentados caso a caso.
 */
class PricingEngineTest extends TestCase
{
    private PricingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new PricingEngine;
    }

    /** Material padrão: R$ 3,20/m², sem espessura (isola a geometria). */
    private function material(array $overrides = []): Material
    {
        return new Material([
            'name' => 'Papelão teste',
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 3.20,
            'default_waste_percent' => 10.0,
            'thickness_mm' => 0.0,
            ...$overrides,
        ]);
    }

    /** Custos neutros: rateio e imposto zerados para isolar uma variável por vez. */
    private function settings(array $overrides = []): CostSetting
    {
        return new CostSetting([
            'energy_tariff_per_kwh' => 1.00,
            'machine_hour_rate' => 60.00,
            'machine_power_kw' => 10.00,
            'labor_hour_rate' => 30.00,
            'overhead_percent' => 0.0,
            'tax_percent' => 0.0,
            'default_profit_margin_percent' => 30.0,
            'currency' => 'BRL',
            ...$overrides,
        ]);
    }

    private function input(array $overrides = []): PricingInput
    {
        return new PricingInput(
            material: $overrides['material'] ?? $this->material(),
            settings: $overrides['settings'] ?? $this->settings(),
            boxModel: $overrides['boxModel'] ?? BoxModel::Rsc,
            widthMm: $overrides['widthMm'] ?? 300,
            heightMm: $overrides['heightMm'] ?? 200,
            depthMm: $overrides['depthMm'] ?? 150,
            quantity: $overrides['quantity'] ?? 100,
            wastePercent: $overrides['wastePercent'] ?? 10.0,
            productionMinutesPerUnit: $overrides['productionMinutesPerUnit'] ?? 2.5,
            profitMarginPercent: $overrides['profitMarginPercent'] ?? 30.0,
            pricingMode: $overrides['pricingMode'] ?? 'markup',
            lidWidthMm: $overrides['lidWidthMm'] ?? null,
            lidDepthMm: $overrides['lidDepthMm'] ?? null,
            lidHeightMm: $overrides['lidHeightMm'] ?? null,
        );
    }

    #[Test]
    public function calcula_a_area_do_plano_de_corte_de_um_rsc(): void
    {
        // RSC 300×200×150, espessura 0:
        //   largura = 2×(300+150) + 35 (aba) = 935 mm
        //   altura  = 200 + 150             = 350 mm
        //   área    = 935 × 350 = 327.250 mm² = 0,32725 m²
        $result = $this->engine->calculate($this->input());

        $this->assertSame(935.0, $result->blankWidthMm);
        $this->assertSame(350.0, $result->blankHeightMm);
        $this->assertSame(0.32725, $result->areaM2PerUnit);
    }

    #[Test]
    public function o_desperdicio_incide_sobre_a_area_e_nao_sobre_o_custo(): void
    {
        // Área bruta = 0,32725 × 1,10 = 0,359975 m²; × 100 un = 35,9975 m²
        $result = $this->engine->calculate($this->input(['wastePercent' => 10.0]));

        $this->assertSame(35.9975, $result->areaM2Total);

        // Custo material = 0,359975 × R$ 3,20 = R$ 1,15192 → 1,1519
        $this->assertSame(1.1519, $result->materialCost);
    }

    #[Test]
    public function decompoe_mao_de_obra_hora_maquina_e_energia_pelo_tempo(): void
    {
        // 2,5 min = 0,0416667 h
        //   mão de obra = 0,0416667 × R$ 30,00 = R$ 1,25
        //   hora-máquina= 0,0416667 × R$ 60,00 = R$ 2,50
        //   energia     = 0,0416667 × 10 kW × R$ 1,00 = R$ 0,4167
        $result = $this->engine->calculate($this->input());

        $this->assertSame(1.25, $result->laborCost);
        $this->assertSame(2.5, $result->machineCost);
        $this->assertSame(0.4167, $result->energyCost);
    }

    #[Test]
    public function markup_de_30_porcento_entrega_margem_real_de_23_porcento(): void
    {
        // Este é o teste que justifica existirem dois modos de precificação.
        // CMV = 1,15192 + 1,25 + 2,50 + 0,4166667 = R$ 5,3185867
        // Preço (markup 30%) = 5,3185867 × 1,30 = R$ 6,9142
        $result = $this->engine->calculate($this->input(['pricingMode' => 'markup']));

        $this->assertSame(5.3186, $result->unitCost);
        $this->assertSame(6.9142, $result->unitPrice);

        // A margem REAL sobre a venda é bem menor que os 30% pedidos.
        $this->assertSame(23.08, $result->effectiveMarginPercent);
    }

    #[Test]
    public function modo_margin_entrega_exatamente_a_margem_pedida(): void
    {
        // Preço = 5,3185867 ÷ (1 − 0,30) = R$ 7,598
        $result = $this->engine->calculate($this->input(['pricingMode' => 'margin']));

        $this->assertSame(7.598, $result->unitPrice);

        // Agora sim: 30% de margem líquida sobre o preço de venda.
        $this->assertSame(30.0, $result->effectiveMarginPercent);
    }

    #[Test]
    public function margem_de_100_porcento_no_modo_margin_e_rejeitada(): void
    {
        // custo ÷ (1 − 1) = divisão por zero.
        $this->expectException(\DomainException::class);

        $this->engine->calculate($this->input([
            'pricingMode' => 'margin',
            'profitMarginPercent' => 100.0,
        ]));
    }

    #[Test]
    public function o_modo_margin_entrega_a_margem_pedida_mesmo_com_imposto(): void
    {
        // Regressão do bug encontrado no teste de navegador: a UI promete
        // "margem líquida sobre o preço final", mas aplicar margem e imposto
        // em sequência entregava 27,6% em vez de 30%.
        //
        // Correto (markup divisor): preço = custo ÷ (1 − 0,30 − 0,08).
        $result = $this->engine->calculate($this->input([
            'pricingMode' => 'margin',
            'profitMarginPercent' => 30.0,
            'settings' => $this->settings(['tax_percent' => 8.0]),
        ]));

        $this->assertSame(30.0, $result->effectiveMarginPercent);
    }

    #[Test]
    public function margem_mais_imposto_acima_de_100_porcento_e_rejeitada(): void
    {
        // Não existe preço finito em que margem + imposto consumam 100% ou
        // mais da venda e ainda sobre algo para pagar o custo.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/menor que 100/');

        $this->engine->calculate($this->input([
            'pricingMode' => 'margin',
            'profitMarginPercent' => 95.0,
            'settings' => $this->settings(['tax_percent' => 8.0]),
        ]));
    }

    #[Test]
    public function converte_material_cotado_em_quilo_usando_a_gramatura(): void
    {
        // R$ 8,50/kg × 0,300 kg/m² = R$ 2,55/m²
        // Custo = 0,359975 m² × 2,55 = R$ 0,91794 → 0,9179
        $material = $this->material([
            'cost_unit' => MaterialUnit::Kilogram,
            'cost_per_unit' => 8.50,
            'grammage_kg_per_m2' => 0.300,
        ]);

        $result = $this->engine->calculate($this->input(['material' => $material]));

        $this->assertSame(0.9179, $result->materialCost);
    }

    #[Test]
    public function material_em_quilo_sem_gramatura_falha_de_forma_explicita(): void
    {
        // Falha alto e cedo em vez de calcular silenciosamente um preço errado.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/gramatura/');

        $this->engine->calculate($this->input([
            'material' => $this->material([
                'cost_unit' => MaterialUnit::Kilogram,
                'cost_per_unit' => 8.50,
                'grammage_kg_per_m2' => null,
            ]),
        ]));
    }

    #[Test]
    public function o_imposto_e_calculado_por_dentro(): void
    {
        // Imposto "por dentro": após recolher 10% do preço, deve sobrar o valor
        // pré-imposto. Preço = 6,91416271 ÷ 0,90 = R$ 7,6824 (e NÃO × 1,10).
        //
        // O divisor usa o preço pré-imposto NÃO arredondado — arredondar a cada
        // etapa acumularia erro ao longo da cadeia de cálculo.
        $result = $this->engine->calculate($this->input([
            'settings' => $this->settings(['tax_percent' => 10.0]),
        ]));

        $this->assertSame(7.6824, $result->unitPrice);

        // Prova de que a conta fecha: preço − imposto = preço pré-imposto.
        $this->assertEqualsWithDelta(
            6.9142,
            $result->unitPrice - ($result->unitPrice * 0.10),
            0.0001,
        );
    }

    #[Test]
    public function o_rateio_de_custos_indiretos_incide_sobre_o_custo_direto(): void
    {
        // Custo direto = R$ 5,3185867; rateio 12% = R$ 0,6382
        $result = $this->engine->calculate($this->input([
            'settings' => $this->settings(['overhead_percent' => 12.0]),
        ]));

        $this->assertSame(0.6382, $result->overheadCost);
        $this->assertSame(5.9568, $result->unitCost);
    }

    #[Test]
    public function o_lucro_desconta_custo_e_imposto(): void
    {
        $result = $this->engine->calculate($this->input([
            'settings' => $this->settings(['tax_percent' => 10.0]),
            'quantity' => 100,
        ]));

        // Lucro = preço total − custo total − imposto (e não preço − custo).
        $expected = round($result->totalPrice - $result->totalCost - $result->taxAmount, 2);

        $this->assertSame($expected, $result->profitAmount);
        $this->assertGreaterThan(0, $result->profitAmount);
    }

    #[Test]
    public function a_quantidade_multiplica_os_totais_mas_nao_os_unitarios(): void
    {
        $one = $this->engine->calculate($this->input(['quantity' => 1]));
        $thousand = $this->engine->calculate($this->input(['quantity' => 1000]));

        $this->assertSame($one->unitCost, $thousand->unitCost);
        $this->assertSame($one->unitPrice, $thousand->unitPrice);
        $this->assertSame(round($one->unitPrice * 1000, 2), $thousand->totalPrice);
    }

    #[Test]
    public function cada_modelo_de_caixa_tem_sua_propria_planificacao(): void
    {
        $areas = [];

        foreach (BoxModel::cases() as $model) {
            $areas[$model->value] = $this->engine
                ->calculate($this->input(['boxModel' => $model]))
                ->areaM2PerUnit;
        }

        // Nenhum modelo pode compartilhar a área de outro: seriam a mesma fórmula.
        $this->assertCount(count($areas), array_unique($areas));

        // A luva (sem topo nem fundo) consome menos que o RSC equivalente.
        $this->assertLessThan($areas['rsc'], $areas['sleeve']);
    }

    #[Test]
    public function a_bandeja_expoe_as_medidas_fisicas_da_tampa(): void
    {
        // Base 300×200×150 em material de 3mm:
        //   largura = 300 + 2×2 (folga) + 2×3 (espessura) = 310
        //   profund.= 150 + 2×2        + 2×3              = 160
        //   altura  = 200 × 0,35                          = 70
        $result = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tray,
            'material' => $this->material(['thickness_mm' => 3.0]),
        ]));

        $this->assertSame(310.0, $result->lidWidthMm);
        $this->assertSame(160.0, $result->lidDepthMm);
        $this->assertSame(70.0, $result->lidHeightMm);
    }

    #[Test]
    public function a_tampa_encaixa_por_fora_da_base(): void
    {
        // Invariante física: sem isto a tampa não desliza sobre a base.
        $result = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tray,
            'material' => $this->material(['thickness_mm' => 3.0]),
        ]));

        $this->assertGreaterThan(300.0, $result->lidWidthMm);
        $this->assertGreaterThan(150.0, $result->lidDepthMm);
    }

    #[Test]
    public function as_medidas_informadas_da_tampa_prevalecem_sobre_a_sugestao(): void
    {
        $result = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tray,
            'lidWidthMm' => 340.0,
            'lidDepthMm' => 190.0,
            'lidHeightMm' => 120.0,
        ]));

        $this->assertSame(340.0, $result->lidWidthMm);
        $this->assertSame(190.0, $result->lidDepthMm);
        $this->assertSame(120.0, $result->lidHeightMm);
    }

    #[Test]
    public function cada_eixo_da_tampa_e_resolvido_de_forma_independente(): void
    {
        // Fixar só a altura precisa deixar largura e profundidade seguindo a
        // base — é o que permite "quero a tampa mais funda, o resto igual".
        $result = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tray,
            'lidHeightMm' => 120.0,
        ]));

        $this->assertSame(120.0, $result->lidHeightMm);
        $this->assertSame(304.0, $result->lidWidthMm);  // 300 + 2×2 de folga
        $this->assertSame(154.0, $result->lidDepthMm);  // 150 + 2×2 de folga
    }

    #[Test]
    public function uma_tampa_maior_consome_mais_material_e_custa_mais(): void
    {
        // A garantia central desta funcionalidade: a tampa informada entra no
        // plano de corte. Se o blank usasse a tampa sugerida, uma tampa mais
        // alta sairia de graça e o orçamento sairia no prejuízo.
        $padrao = $this->engine->calculate($this->input(['boxModel' => BoxModel::Tray]));

        $alta = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tray,
            'lidHeightMm' => 150.0, // sugerida seria 70
        ]));

        $this->assertGreaterThan($padrao->areaM2PerUnit, $alta->areaM2PerUnit);
        $this->assertGreaterThan($padrao->materialCost, $alta->materialCost);
        $this->assertGreaterThan($padrao->unitPrice, $alta->unitPrice);
    }

    #[Test]
    public function a_tampa_informada_e_ignorada_em_modelos_sem_tampa(): void
    {
        // Enviar medidas de tampa para um saco não pode alterar o consumo.
        $limpo = $this->engine->calculate($this->input(['boxModel' => BoxModel::Pouch]));

        $comLixo = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Pouch,
            'lidWidthMm' => 999.0,
            'lidHeightMm' => 999.0,
        ]));

        $this->assertSame($limpo->areaM2PerUnit, $comLixo->areaM2PerUnit);
        $this->assertNull($comLixo->lidWidthMm);
    }

    #[Test]
    public function modelos_sem_tampa_nao_reportam_medidas_de_tampa(): void
    {
        foreach ([BoxModel::Rsc, BoxModel::Sleeve, BoxModel::Pouch] as $model) {
            $result = $this->engine->calculate($this->input(['boxModel' => $model]));

            $this->assertNull($result->lidWidthMm, "modelo {$model->value}");
            $this->assertNull($result->lidDepthMm, "modelo {$model->value}");
            $this->assertNull($result->lidHeightMm, "modelo {$model->value}");
        }
    }

    #[Test]
    public function a_espessura_do_material_aumenta_o_consumo(): void
    {
        $fino = $this->engine->calculate($this->input(['material' => $this->material(['thickness_mm' => 0.0])]));
        $grosso = $this->engine->calculate($this->input(['material' => $this->material(['thickness_mm' => 5.0])]));

        // Cada vinco consome material: papelão de 5mm exige um blank maior.
        $this->assertGreaterThan($fino->areaM2PerUnit, $grosso->areaM2PerUnit);
    }
}

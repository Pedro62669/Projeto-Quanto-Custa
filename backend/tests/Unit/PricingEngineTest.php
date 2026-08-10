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
            /*
             * O modelo livre fica de fora porque ele não TEM planificação: a
             * área vem das peças que o usuário mede, e sem peças o motor recusa
             * — comportamento que o teste do modelo livre cobre à parte.
             */
            if ($model->isFree()) {
                continue;
            }

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

    /* ── Caixa gaveta ──────────────────────────────────────────────────── */

    #[Test]
    public function a_gaveta_soma_as_duas_pecas(): void
    {
        // Gaveta 300×200×150, espessura 0:
        //   gaveta = (300 + 2×200) × (150 + 2×200) = 700 × 550 = 385.000
        //   luva   = 2×((300+2) + (200+2)) + 35 = 1.043 de perímetro
        //            × 150 de profundidade                    = 156.450
        //   total  = 541.450 mm² = 0,54145 m²
        $result = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Drawer,
        ]));

        $this->assertEqualsWithDelta(0.54145, $result->areaM2PerUnit, 0.000001);
    }

    #[Test]
    public function em_proporcoes_tipicas_a_gaveta_consome_mais_que_um_rsc(): void
    {
        // Sanidade para uma caixa de proporções comuns (300×200×150).
        //
        // NÃO é invariante geral, e o qualificador no nome é intencional: num
        // formato largo e raso as abas do RSC valem meia profundidade cada e
        // dominam o blank, a ponto de o RSC gastar MAIS que a gaveta. Afirmar
        // "duas peças sempre custam mais" seria falso.
        $gaveta = $this->engine->calculate($this->input(['boxModel' => BoxModel::Drawer]));
        $rsc = $this->engine->calculate($this->input(['boxModel' => BoxModel::Rsc]));

        $this->assertGreaterThan($rsc->areaM2PerUnit, $gaveta->areaM2PerUnit);
    }

    #[Test]
    public function numa_caixa_larga_e_rasa_o_rsc_pode_consumir_mais(): void
    {
        // O contra-exemplo, documentado como teste para que ninguém "conserte"
        // a fórmula da gaveta achando que ela está barata demais.
        $dims = ['widthMm' => 800, 'heightMm' => 200, 'depthMm' => 800];

        $gaveta = $this->engine->calculate($this->input([...$dims, 'boxModel' => BoxModel::Drawer]));
        $rsc = $this->engine->calculate($this->input([...$dims, 'boxModel' => BoxModel::Rsc]));

        $this->assertGreaterThan($gaveta->areaM2PerUnit, $rsc->areaM2PerUnit);
    }

    #[Test]
    public function a_luva_da_gaveta_cresce_com_a_espessura(): void
    {
        // A luva envolve a gaveta JÁ MONTADA: material mais grosso engorda a
        // gaveta e obriga a luva a ser maior. Ignorar isso produziria uma
        // gaveta que não entra na própria luva.
        $fina = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Drawer,
            'material' => $this->material(['thickness_mm' => 0.0]),
        ]));

        $grossa = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Drawer,
            'material' => $this->material(['thickness_mm' => 7.0]),
        ]));

        $this->assertGreaterThan($fina->areaM2PerUnit, $grossa->areaM2PerUnit);
    }

    #[Test]
    public function a_gaveta_nao_tem_tampa_separada(): void
    {
        // A luva É a peça externa; não existe tampa além dela.
        $result = $this->engine->calculate($this->input(['boxModel' => BoxModel::Drawer]));

        $this->assertNull($result->lidWidthMm);
        $this->assertNull($result->lidHeightMm);
    }

    /* ── Embalagem cilíndrica ──────────────────────────────────────────── */

    #[Test]
    public function o_tubo_planifica_o_corpo_pela_circunferencia(): void
    {
        // Tubo Ø100 × 200mm, espessura 0:
        //   corpo   = (π × 100 + 35 de aba) × 200 = 349,1593 × 200 = 69.831,85
        //   fundo   = 100²                                        = 10.000,00
        //   tampa Ø = 100 + 2×2 de folga                          =     104
        //   saia    = (π × 104 + 35) × 70 = 361,7256 × 70         = 25.320,79
        //   disco   = 104²                                        = 10.816,00
        //   total   = 115.968,64 mm² ≈ 0,115969 m²
        $result = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tube,
            'widthMm' => 100,
            'heightMm' => 200,
        ]));

        $this->assertEqualsWithDelta(0.115969, $result->areaM2PerUnit, 0.000001);
    }

    #[Test]
    public function a_espessura_alonga_o_corpo_do_tubo(): void
    {
        // A invariante que justifica usar a circunferência da LINHA MÉDIA:
        // enrolar uma chapa faz a face externa percorrer caminho maior que a
        // interna. Planificar pelo diâmetro interno produziria um tubo que
        // não fecha.
        $fino = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tube,
            'material' => $this->material(['thickness_mm' => 0.0]),
        ]));

        $grosso = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tube,
            'material' => $this->material(['thickness_mm' => 7.0]),
        ]));

        $this->assertGreaterThan($fino->areaM2PerUnit, $grosso->areaM2PerUnit);
    }

    #[Test]
    public function a_profundidade_e_irrelevante_num_cilindro(): void
    {
        // Só o diâmetro (largura) e a altura definem um cilindro. Se a
        // profundidade entrasse na conta, o mesmo tubo teria dois preços.
        $a = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tube,
            'widthMm' => 100,
            'depthMm' => 100,
        ]));

        $b = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tube,
            'widthMm' => 100,
            'depthMm' => 2500,
        ]));

        $this->assertSame($a->areaM2PerUnit, $b->areaM2PerUnit);
        $this->assertSame($a->unitPrice, $b->unitPrice);
    }

    #[Test]
    public function a_tampa_do_tubo_e_circular(): void
    {
        // Largura e profundidade iguais: uma tampa com medidas diferentes
        // seria oval e não encaixaria no corpo redondo.
        $result = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tube,
            'widthMm' => 100,
            'depthMm' => 2500, // ignorada
        ]));

        $this->assertSame(104.0, $result->lidWidthMm);
        $this->assertSame(104.0, $result->lidDepthMm);
    }

    #[Test]
    public function o_tubo_aceita_medidas_de_tampa_informadas(): void
    {
        $result = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tube,
            'widthMm' => 100,
            'lidHeightMm' => 130.0,
        ]));

        $this->assertSame(130.0, $result->lidHeightMm);

        // E a tampa maior precisa encarecer, como na bandeja.
        $padrao = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tube,
            'widthMm' => 100,
        ]));

        $this->assertGreaterThan($padrao->materialCost, $result->materialCost);
    }

    #[Test]
    public function um_tubo_consome_menos_que_a_caixa_que_o_contem(): void
    {
        // Sanidade geométrica: o cilindro inscrito no cubo usa menos material
        // que um RSC das mesmas medidas externas.
        $tubo = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Tube,
            'widthMm' => 200, 'heightMm' => 200, 'depthMm' => 200,
        ]));

        $caixa = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Rsc,
            'widthMm' => 200, 'heightMm' => 200, 'depthMm' => 200,
        ]));

        $this->assertLessThan($caixa->areaM2PerUnit, $tubo->areaM2PerUnit);
    }

    /* ── Mailer box ────────────────────────────────────────────────────── */

    #[Test]
    public function a_mailer_soma_os_paineis_da_faca(): void
    {
        /*
         * Mailer 300×200×150, espessura 0 — painel a painel, na ordem em que
         * mailer/box-mailer.blend os corta. Com t = 0 os planos coincidem com
         * as medidas pedidas, o que deixa a conta conferível a olho.
         *
         *   aba da parede = 0,24×150                   =  36
         *   aba da tampa  = 0,86×200                   = 172
         *   língua = parede + espessura                = 150 (t = 0)
         *   lingueta = min(0,22×200; 0,06×300)         =  18
         *   barbatana = 0,53×36 por 0,459×150          =  19,08 × 68,85
         *   fendas em 0,29×150 ± 0,11×150              =  27 … 60
         *
         *   fundo            = 300 × 150               =  45.000
         *   parede frontal   = 300 × 200               =  60.000
         *   parede traseira  = 300 × 200               =  60.000
         *   4 abas de parede = 4 × (36 × 200)          =  28.800
         *   tampa            = 300 × 150               =  45.000
         *   2 abas da tampa  = 2 × (120,9 × 172 − chanfro 2.769,05)
         *                                              =  38.820,54
         *   língua           = 300 × 150               =  45.000
         *   2 barbatanas     = 2 × (19,08 × 68,85)     =   2.627,32
         *   2 rolos externos = 2 × (150 × 200)         =  60.000
         *   2 pontes         = 2 × (150 × 0)           =       0
         *   2 rolos internos = 2 × (150 × 200)         =  60.000
         *   4 linguetas      = 4 × (33 × 18)           =   2.376
         *   total                                      = 447.623,85 mm²
         */
        $result = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Mailer,
        ]));

        $this->assertEqualsWithDelta(0.44762385, $result->areaM2PerUnit, 0.000001);
    }

    #[Test]
    public function na_mailer_cada_milimetro_de_altura_pesa_mais_que_o_dobro_do_rsc(): void
    {
        /*
         * A ASSINATURA do modelo, e a razão de ele existir separado do RSC.
         *
         * A lateral rolada sobe, dobra e desce: cada milímetro de altura entra
         * duas vezes no blank, contra uma no RSC. Somado à parede frontal, que
         * também rola, e às abas da tampa (que também crescem com a altura), a
         * mailer fica mais de duas vezes mais sensível à altura.
         *
         * Se alguém "simplificar" o rolo para parede simples, esta razão cai
         * para perto de 1 e o teste quebra — que é exatamente o ponto.
         */
        /*
         * Caixa FUNDA (400 de profundidade) de propósito: numa caixa rasa o
         * teto das abas pega e trava justamente os termos que crescem com a
         * altura, mascarando a assinatura que este teste existe para fixar.
         */
        $delta = function (BoxModel $model): float {
            $baixa = $this->engine->calculate($this->input([
                'boxModel' => $model, 'heightMm' => 100, 'depthMm' => 400,
            ]));
            $alta = $this->engine->calculate($this->input([
                'boxModel' => $model, 'heightMm' => 200, 'depthMm' => 400,
            ]));

            return $alta->areaM2PerUnit - $baixa->areaM2PerUnit;
        };

        $this->assertGreaterThan(2.0, $delta(BoxModel::Mailer) / $delta(BoxModel::Rsc));
    }

    #[Test]
    public function a_lingueta_da_mailer_para_na_largura_da_caixa(): void
    {
        /*
         * A lingueta prende o rolo ao fundo entrando numa fenda que fica a uma
         * lingueta da borda. Ela acompanha a ALTURA, mas numa caixa alta e
         * estreita 0,22 da altura passaria da largura disponível: a fenda
         * cairia fora do fundo e a caixa seria cobrada por uma trava que não
         * existe. Daí o segundo teto, em fração da largura.
         *
         * 300×400×80 (alta e rasa): lingueta = min(0,22×400 = 88; 0,06×300) = 18.
         *   aba de parede = 0,24×80 = 19,2   aba da tampa = 0,86×400 = 344
         *   língua = parede, mas o teto é a tampa: min(400; 80) = 80
         *   fundo            = 300 × 80                =  24.000
         *   paredes          = 2 × (300 × 400)         = 240.000
         *   4 abas de parede = 4 × (19,2 × 400)        =  30.720
         *   tampa            = 300 × 80                =  24.000
         *   2 abas da tampa  = 2 × (64,48 × 344 − chanfro 5.535,17)
         *                                              =  33.285,99
         *   língua           = 300 × 80                =  24.000
         *   2 barbatanas     = 2 × (10,176 × 36,72)    =     747,33
         *   rolos + pontes   = 2 × (80 × 400) × 2      = 128.000
         *   4 linguetas      = 4 × (17,6 × 18)         =   1.267,20
         *   total                                      = 506.020,52 mm²
         */
        $alta = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Mailer,
            'widthMm' => 300, 'heightMm' => 400, 'depthMm' => 80,
        ]));

        $this->assertEqualsWithDelta(0.50602052, $alta->areaM2PerUnit, 0.000001);

        /*
         * O outro lado do ramo: caixa larga e rasa, onde quem governa é a
         * altura e a lingueta encolhe junto com ela.
         *
         * 400×40×150: lingueta = min(0,22×40 = 8,8; 0,06×400 = 24) = 8,8.
         *   fundo            = 400 × 150               =  60.000
         *   paredes          = 2 × (400 × 40)          =  32.000
         *   4 abas de parede = 4 × (36 × 40)           =   5.760
         *   tampa            = 400 × 150               =  60.000
         *   2 abas da tampa  = 2 × (120,9 × 34,4 − chanfro 110,76)
         *                                              =   8.207,16
         *   língua           = 400 × 40                =  16.000
         *   2 barbatanas     = 2 × (19,08 × 18,36)     =     700,62
         *   rolos + pontes   = 2 × (150 × 40) × 2      =  24.000
         *   4 linguetas      = 4 × (33 × 8,8)          =   1.161,60
         *   total                                      = 207.829,38 mm²
         */
        $rasa = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Mailer,
            'widthMm' => 400, 'heightMm' => 40, 'depthMm' => 150,
        ]));

        $this->assertEqualsWithDelta(0.20782938, $rasa->areaM2PerUnit, 0.000001);
    }

    #[Test]
    public function a_volta_do_rolo_consome_material(): void
    {
        /*
         * A PONTE do rolo é material de verdade: a dobra de 180° no topo
         * engole duas espessuras, e são duas pontes por caixa. Com papelão
         * fino ela some (2×0 = 0) e com 7mm vale 14mm ao longo de toda a
         * profundidade.
         *
         * A espessura entra uma segunda vez, e é a razão de o consumo subir:
         * as medidas pedidas são as INTERNAS, então os planos de dobra se
         * afastam para dar lugar às camadas — a largura ganha 5t, e cada
         * camada do rolo precisa contornar as outras.
         *
         * 300×200×150 com t=7 → planos 335 × 157 × 203,5.
         *   ponte          = 2 × (157 × 14)       =   4.396
         *   total                                  = 477.820,01 mm²
         */
        $fina = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Mailer,
            'material' => $this->material(['thickness_mm' => 0.0]),
        ]));

        $grossa = $this->engine->calculate($this->input([
            'boxModel' => BoxModel::Mailer,
            'material' => $this->material(['thickness_mm' => 7.0]),
        ]));

        $this->assertEqualsWithDelta(0.47782001, $grossa->areaM2PerUnit, 0.000001);
        $this->assertGreaterThan($fina->areaM2PerUnit, $grossa->areaM2PerUnit);
    }

    #[Test]
    public function a_mailer_nao_tem_tampa_separada(): void
    {
        // A tampa é articulada, parte da mesma chapa: não existe peça solta a
        // dimensionar, e o formulário não deve oferecer campos de tampa.
        $result = $this->engine->calculate($this->input(['boxModel' => BoxModel::Mailer]));

        $this->assertFalse(BoxModel::Mailer->hasSeparateLid());
        $this->assertNull($result->lidWidthMm);
        $this->assertNull($result->lidHeightMm);
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

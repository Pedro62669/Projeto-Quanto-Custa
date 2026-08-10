<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BoxModel;
use App\Enums\MaterialUnit;
use App\Models\CostSetting;
use App\Models\Material;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\PricingInput;
use Illuminate\Console\Command;

/**
 * Exporta casos de teste do motor PHP para um JSON consumido pelo motor TS.
 *
 * Este comando é a metade backend do teste de paridade. O motor duplicado
 * (PHP para gravar, TS para o preview em tempo real) é uma decisão consciente
 * de performance, mas cria o risco de os dois divergirem em silêncio depois de
 * uma alteração feita em apenas um dos lados.
 *
 * O fixture gerado aqui é a fonte de verdade: `npm run test:parity` roda o
 * motor TS contra estes casos e falha se qualquer campo divergir.
 *
 * Uso: php artisan pricing:export-fixtures
 */
class ExportPricingFixtures extends Command
{
    protected $signature = 'pricing:export-fixtures
                            {--path= : Caminho do JSON de saída}';

    protected $description = 'Gera os casos de paridade entre os motores PHP e TypeScript';

    public function handle(PricingEngine $engine): int
    {
        $path = $this->option('path')
            ?: base_path('../frontend/lib/pricing/__fixtures__/parity.json');

        $cases = [];

        foreach ($this->scenarios() as $name => $scenario) {
            $input = new PricingInput(
                material: $scenario['material'],
                settings: $scenario['settings'],
                boxModel: $scenario['boxModel'],
                widthMm: $scenario['widthMm'],
                heightMm: $scenario['heightMm'],
                depthMm: $scenario['depthMm'],
                quantity: $scenario['quantity'],
                wastePercent: $scenario['wastePercent'],
                productionMinutesPerUnit: $scenario['productionMinutes'],
                profitMarginPercent: $scenario['marginPercent'],
                pricingMode: $scenario['pricingMode'],
                lidWidthMm: $scenario['lidWidthMm'],
                lidDepthMm: $scenario['lidDepthMm'],
                lidHeightMm: $scenario['lidHeightMm'],
                companyMinuteCost: $scenario['companyMinuteCost'],
                wrapCostPerM2: $scenario['wrapCostPerM2'],
                hardware: $scenario['hardware'],
                cradle: $scenario['cradle'],
                customParts: $scenario['customParts'],
            );

            $cases[] = [
                'name' => $name,

                // Formato idêntico ao que o motor TS consome: o material é
                // achatado para cost_per_m2, como a API o entrega ao frontend.
                'material' => [
                    'cost_per_m2' => round($scenario['material']->costPerSquareMeter(), 10),
                    'thickness_mm' => $scenario['material']->thickness_mm,
                ],
                'settings' => [
                    ...$scenario['settings']->only([
                        'energy_tariff_per_kwh', 'machine_hour_rate', 'machine_power_kw',
                        'labor_hour_rate', 'overhead_percent', 'tax_percent',
                    ]),

                    /*
                     * O modo hora-empresa chega ao TS pelas configurações, e não
                     * pela spec, porque é decisão da EMPRESA e não do orçamento.
                     * `company_minute_cost` já vem resolvido — o motor local não
                     * soma despesas fixas, ele consome o número que a API somou.
                     */
                    'use_company_hour' => $scenario['companyMinuteCost'] !== null,
                    'company_minute_cost' => $scenario['companyMinuteCost'],
                ],
                'spec' => [
                    'box_model' => $scenario['boxModel']->value,
                    'width_mm' => $scenario['widthMm'],
                    'height_mm' => $scenario['heightMm'],
                    'depth_mm' => $scenario['depthMm'],
                    'quantity' => $scenario['quantity'],
                    'waste_percent' => $scenario['wastePercent'],
                    'production_minutes_per_unit' => $scenario['productionMinutes'],
                    'profit_margin_percent' => $scenario['marginPercent'],
                    'pricing_mode' => $scenario['pricingMode'],
                    'lid_width_mm' => $scenario['lidWidthMm'],
                    'lid_depth_mm' => $scenario['lidDepthMm'],
                    'lid_height_mm' => $scenario['lidHeightMm'],

                    // Lista de materiais: já normalizada, como a API entrega.
                    'wrap_cost_per_m2' => $scenario['wrapCostPerM2'],
                    'hardware' => $scenario['hardware'],
                    'cradle' => $scenario['cradle'],

                    // Peças do modelo livre: já normalizadas, como a API entrega.
                    'custom_parts' => $scenario['customParts'],
                ],
                'expected' => $engine->calculate($input)->toArray(),
            ];
        }

        @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, json_encode([
            'engine_version' => PricingEngine::VERSION,
            'generated_at' => now()->toIso8601String(),
            'cases' => $cases,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info(count($cases).' casos exportados para '.realpath($path));

        return self::SUCCESS;
    }

    /**
     * Monta a especificação de um berço, com os defaults do formulário.
     *
     * @return array<string, mixed>
     */
    private function cradle(
        string $type,
        float $costPerUnit,
        int $rows = 1,
        int $columns = 1,
        float $heightRatio = 1.0,
        float $stripThicknessMm = 0.0,
    ): array {
        return [
            'type' => $type,
            'cost_per_unit' => $costPerUnit,
            'rows' => $rows,
            'columns' => $columns,
            'height_ratio' => $heightRatio,
            'strip_thickness_mm' => $stripThicknessMm,
        ];
    }

    /**
     * Matriz de cenários.
     *
     * Cobre cada eixo do cálculo isoladamente e depois combinados: todos os
     * modelos de caixa, as duas unidades de compra, os dois modos de
     * precificação, e os componentes que só aparecem quando ligados (rateio,
     * imposto, espessura).
     *
     * @return array<string, array<string, mixed>>
     */
    private function scenarios(): array
    {
        $base = fn (array $o = []) => [
            'material' => $this->material($o['material'] ?? []),
            'settings' => $this->settings($o['settings'] ?? []),
            'boxModel' => $o['boxModel'] ?? BoxModel::Rsc,
            'widthMm' => $o['widthMm'] ?? 300.0,
            'heightMm' => $o['heightMm'] ?? 200.0,
            'depthMm' => $o['depthMm'] ?? 150.0,
            'quantity' => $o['quantity'] ?? 100,
            'wastePercent' => $o['wastePercent'] ?? 10.0,
            'productionMinutes' => $o['productionMinutes'] ?? 2.5,
            'marginPercent' => $o['marginPercent'] ?? 30.0,
            'pricingMode' => $o['pricingMode'] ?? 'markup',
            'lidWidthMm' => $o['lidWidthMm'] ?? null,
            'lidDepthMm' => $o['lidDepthMm'] ?? null,
            'lidHeightMm' => $o['lidHeightMm'] ?? null,

            // null = regime de estimativa (labor_hour_rate + overhead_percent).
            'companyMinuteCost' => $o['companyMinuteCost'] ?? null,

            // Lista de materiais: null/vazio = material único, sem ferragem.
            'wrapCostPerM2' => $o['wrapCostPerM2'] ?? null,
            'hardware' => $o['hardware'] ?? [],

            // null = caixa vazia, sem berço.
            'cradle' => $o['cradle'] ?? null,

            // vazio = qualquer modelo com geometria; só o livre usa peças.
            'customParts' => $o['customParts'] ?? [],
        ];

        /** Uma peça do modelo livre, já normalizada como a API a entrega. */
        $peca = fn (
            string $role,
            float $costPerM2,
            float $wastePercent,
            float $widthMm,
            float $lengthMm,
            int $quantity,
        ) => [
            'role' => $role,
            'cost_per_m2' => $costPerM2,
            'waste_percent' => $wastePercent,
            'width_mm' => $widthMm,
            'length_mm' => $lengthMm,
            'quantity' => $quantity,
        ];

        $scenarios = [
            'rsc-padrao' => $base(),
            'modo-margin' => $base(['pricingMode' => 'margin']),

            /*
             * Modo hora-empresa: a mão de obra sai do custo do minuto e o
             * rateio percentual é ignorado.
             *
             * O caso 'hora-empresa-com-rateio-ignorado' é o que importa: manda
             * overhead_percent alto JUNTO com o modo ligado, e o resultado
             * esperado tem overhead_cost zerado. Se algum dia alguém somar os
             * dois regimes — em qualquer uma das duas linguagens — é este caso
             * que denuncia.
             */
            'hora-empresa' => $base(['companyMinuteCost' => 0.9803]),
            'hora-empresa-com-rateio-ignorado' => $base([
                'companyMinuteCost' => 0.9803,
                'settings' => ['overhead_percent' => 35.0],
            ]),
            'hora-empresa-com-imposto' => $base([
                'companyMinuteCost' => 1.1112,
                'settings' => ['tax_percent' => 8.0],
            ]),
            'hora-empresa-tempo-zero' => $base([
                'companyMinuteCost' => 0.9803,
                'productionMinutes' => 0.0,
            ]),
            'hora-empresa-minuto-caro' => $base([
                'companyMinuteCost' => 12.5,
                'productionMinutes' => 8.0,
            ]),
            'hora-empresa-modo-margin' => $base([
                'companyMinuteCost' => 0.8333,
                'pricingMode' => 'margin',
            ]),

            /*
             * Cartonagem rígida: tampa solta telescópica.
             *
             * O caso 'rigida-sem-revestimento' é o que protege a fórmula: mesmo
             * modelo, mesmo blank de cinza, revestimento null. Se algum dia
             * alguém somar a área do revestimento à da estrutura — em qualquer
             * uma das duas linguagens — este caso e o de baixo deixam de ter a
             * mesma `area_m2_per_unit` e a paridade acusa.
             */
            'rigida-sem-revestimento' => $base(['boxModel' => BoxModel::RigidTelescopic]),
            'rigida-revestida' => $base([
                'boxModel' => BoxModel::RigidTelescopic,
                'wrapCostPerM2' => 22.50,
            ]),
            'rigida-revestida-espessa' => $base([
                'boxModel' => BoxModel::RigidTelescopic,
                'material' => ['thickness_mm' => 2.5],
                'wrapCostPerM2' => 22.50,
            ]),
            'rigida-tampa-manual' => $base([
                'boxModel' => BoxModel::RigidTelescopic,
                'wrapCostPerM2' => 22.50,
                'lidWidthMm' => 340.0, 'lidDepthMm' => 190.0, 'lidHeightMm' => 120.0,
            ]),
            'rigida-com-imas' => $base([
                'boxModel' => BoxModel::RigidTelescopic,
                'wrapCostPerM2' => 22.50,
                'hardware' => [['cost_per_piece' => 0.42, 'quantity' => 4]],
            ]),
            // Duas linhas de ferragem: ímãs e fita de cetim, como uma caixa
            // ímã real leva. Prova que a soma percorre a lista inteira.
            'rigida-ferragem-multipla' => $base([
                'boxModel' => BoxModel::RigidTelescopic,
                'wrapCostPerM2' => 22.50,
                'hardware' => [
                    ['cost_per_piece' => 0.42, 'quantity' => 4],
                    ['cost_per_piece' => 1.85, 'quantity' => 1],
                ],
            ]),
            // Ferragem sem cartonagem rígida: um RSC com fecho. O motor não
            // deve exigir revestimento para aceitar ferragem.
            'ferragem-em-modelo-dobrado' => $base([
                'hardware' => [['cost_per_piece' => 2.10, 'quantity' => 2]],
            ]),
            /*
             * Caixa livro. O par sem-aba / com-aba é o que protege a canaleta:
             * a variação com aba tem UM painel e UMA dobradiça a mais, então o
             * cinza e o revestimento precisam subir em proporções DIFERENTES —
             * o cinza só pelo painel, o papel pelo painel e pelo vão. Quem
             * somar a canaleta no cinza faz os dois subirem igual, e o par
             * denuncia.
             */
            'livro' => $base(['boxModel' => BoxModel::RigidBook, 'wrapCostPerM2' => 22.50]),
            'livro-com-aba' => $base([
                'boxModel' => BoxModel::RigidBookFlap,
                'wrapCostPerM2' => 22.50,
            ]),
            'livro-espesso' => $base([
                'boxModel' => BoxModel::RigidBook,
                'material' => ['thickness_mm' => 2.5],
                'wrapCostPerM2' => 22.50,
            ]),
            // Espessura zero: a canaleta vira zero junto, e o modelo não pode
            // degenerar nem dividir por nada.
            'livro-sem-espessura' => $base([
                'boxModel' => BoxModel::RigidBook,
                'material' => ['thickness_mm' => 0.0],
                'wrapCostPerM2' => 22.50,
            ]),
            'livro-raso' => $base([
                'boxModel' => BoxModel::RigidBookFlap,
                'heightMm' => 25.0,
                'wrapCostPerM2' => 22.50,
            ]),
            'livro-com-imas' => $base([
                'boxModel' => BoxModel::RigidBookFlap,
                'wrapCostPerM2' => 22.50,
                'hardware' => [['cost_per_piece' => 0.42, 'quantity' => 4]],
            ]),

            /*
             * Caixa ímã. O trio prova que as três variações são GEOMÉTRICAS: a
             * aba envolvente consome mais capa que a simples, e as laterais
             * acrescentam painéis próprios com virada nas quatro bordas. Se
             * alguém as reduzir ao mesmo blank, os três casos convergem e a
             * paridade não acusa — mas os testes PHP acusam.
             */
            'ima' => $base(['boxModel' => BoxModel::RigidMagnet, 'wrapCostPerM2' => 22.50]),
            'ima-laterais' => $base([
                'boxModel' => BoxModel::RigidMagnetSide,
                'wrapCostPerM2' => 22.50,
                'hardware' => [['cost_per_piece' => 0.42, 'quantity' => 4]],
            ]),
            'ima-envolvente' => $base([
                'boxModel' => BoxModel::RigidMagnetWrap,
                'wrapCostPerM2' => 22.50,
                'hardware' => [['cost_per_piece' => 0.42, 'quantity' => 2]],
            ]),
            'ima-espesso' => $base([
                'boxModel' => BoxModel::RigidMagnetSide,
                'material' => ['thickness_mm' => 2.5],
                'wrapCostPerM2' => 22.50,
            ]),
            // Caixa rasa: a aba envolvente vira a maior fração da capa, e é
            // onde a razão de avanço sob o fundo mais pesa.
            'ima-envolvente-raso' => $base([
                'boxModel' => BoxModel::RigidMagnetWrap,
                'heightMm' => 25.0,
                'wrapCostPerM2' => 22.50,
            ]),

            /*
             * Berços. Um caso por tipo, para que cada fórmula tenha seu próprio
             * ponto fixo, mais as bordas onde a grade degenera.
             *
             * `berco-espuma` é o único volumétrico: se alguém tratá-lo como
             * área em qualquer uma das duas linguagens, o custo muda de ordem
             * de grandeza e este caso denuncia na primeira comparação.
             */
            'berco-espuma' => $base([
                'cradle' => $this->cradle('foam', costPerUnit: 850.00),
            ]),
            'berco-nicho-cartonagem' => $base([
                'cradle' => $this->cradle('board_niche', costPerUnit: 5.00),
            ]),
            'berco-nicho-papel' => $base([
                'cradle' => $this->cradle('paper_niche', costPerUnit: 3.20),
            ]),
            'berco-papel-dobrado' => $base([
                'cradle' => $this->cradle('paper_fold', costPerUnit: 3.20),
            ]),
            'berco-grade-3x4' => $base([
                'cradle' => $this->cradle('divider_grid', costPerUnit: 5.00, rows: 3, columns: 4),
            ]),
            // Grade 1×1 é "sem divisória": zero tiras, custo zero, e nada pode
            // estourar por dividir a caixa em uma parte só.
            'berco-grade-degenerada' => $base([
                'cradle' => $this->cradle('divider_grid', costPerUnit: 5.00, rows: 1, columns: 1),
            ]),
            // Grade densa numa caixa estreita: as tiras que se cruzam podem
            // consumir a largura inteira, e o comprimento não pode ir a negativo.
            'berco-grade-densa-em-caixa-estreita' => $base([
                'widthMm' => 60.0, 'depthMm' => 60.0,
                'cradle' => $this->cradle('divider_grid', costPerUnit: 5.00, rows: 8, columns: 8,
                    stripThicknessMm: 3.0),
            ]),
            'berco-meia-altura' => $base([
                'cradle' => $this->cradle('board_niche', costPerUnit: 5.00, heightRatio: 0.5),
            ]),
            // Berço numa caixa rígida com revestimento e ferragem: o caso
            // completo, onde todas as linhas da BOM coexistem.
            'berco-em-caixa-rigida-completa' => $base([
                'boxModel' => BoxModel::RigidMagnetSide,
                'wrapCostPerM2' => 22.50,
                'hardware' => [['cost_per_piece' => 0.42, 'quantity' => 4]],
                'cradle' => $this->cradle('foam', costPerUnit: 850.00),
            ]),

            'rigida-quantidade-alta' => $base([
                'boxModel' => BoxModel::RigidTelescopic,
                'wrapCostPerM2' => 22.50,
                'hardware' => [['cost_per_piece' => 0.42, 'quantity' => 4]],
                'quantity' => 5000,
            ]),
            'margem-zero' => $base(['marginPercent' => 0.0]),
            'margem-alta-markup' => $base(['marginPercent' => 250.0]),
            'margem-99-margin' => $base(['marginPercent' => 99.0, 'pricingMode' => 'margin']),
            'sem-desperdicio' => $base(['wastePercent' => 0.0]),
            'desperdicio-alto' => $base(['wastePercent' => 45.5]),
            'com-rateio' => $base(['settings' => ['overhead_percent' => 12.0]]),
            'com-imposto' => $base(['settings' => ['tax_percent' => 8.0]]),
            'rateio-e-imposto' => $base(['settings' => ['overhead_percent' => 12.0, 'tax_percent' => 8.0]]),
            'material-por-quilo' => $base(['material' => [
                'cost_unit' => MaterialUnit::Kilogram,
                'cost_per_unit' => 8.50,
                'grammage_kg_per_m2' => 0.300,
            ]]),
            'material-espesso' => $base(['material' => ['thickness_mm' => 7.0]]),
            'caixa-minima' => $base(['widthMm' => 10.0, 'heightMm' => 10.0, 'depthMm' => 10.0, 'quantity' => 1]),
            'caixa-maxima' => $base(['widthMm' => 3000.0, 'heightMm' => 3000.0, 'depthMm' => 3000.0, 'quantity' => 1]),
            'dimensoes-fracionadas' => $base(['widthMm' => 237.5, 'heightMm' => 118.3, 'depthMm' => 91.7]),
            'quantidade-alta' => $base(['quantity' => 1000000]),
            'tempo-zero' => $base(['productionMinutes' => 0.0]),
            // Tampa informada pelo usuário: cada eixo e a combinação completa.
            'tampa-manual-completa' => $base([
                'boxModel' => BoxModel::Tray,
                'lidWidthMm' => 340.0, 'lidDepthMm' => 190.0, 'lidHeightMm' => 120.0,
            ]),
            'tampa-so-altura' => $base(['boxModel' => BoxModel::Tray, 'lidHeightMm' => 120.0]),
            'tampa-so-largura' => $base(['boxModel' => BoxModel::Tray, 'lidWidthMm' => 355.5]),
            'tampa-manual-espessa' => $base([
                'boxModel' => BoxModel::Tray,
                'material' => ['thickness_mm' => 7.0],
                'lidWidthMm' => 340.0, 'lidHeightMm' => 95.5,
            ]),
            // Medidas de tampa num modelo sem tampa: devem ser ignoradas.
            'tampa-ignorada-em-saco' => $base([
                'boxModel' => BoxModel::Pouch,
                'lidWidthMm' => 999.0, 'lidHeightMm' => 999.0,
            ]),

            // Embalagem cilíndrica: largura = diâmetro, profundidade ignorada.
            'tubo-padrao' => $base(['boxModel' => BoxModel::Tube, 'widthMm' => 100.0, 'heightMm' => 200.0]),
            'tubo-espesso' => $base([
                'boxModel' => BoxModel::Tube, 'widthMm' => 100.0, 'heightMm' => 200.0,
                'material' => ['thickness_mm' => 7.0],
            ]),
            'tubo-profundidade-absurda' => $base([
                'boxModel' => BoxModel::Tube, 'widthMm' => 100.0, 'depthMm' => 2500.0,
            ]),
            'tubo-tampa-manual' => $base([
                'boxModel' => BoxModel::Tube, 'widthMm' => 100.0,
                'lidWidthMm' => 118.0, 'lidHeightMm' => 130.0,
            ]),
            'tubo-fracionado' => $base([
                'boxModel' => BoxModel::Tube, 'widthMm' => 87.3, 'heightMm' => 241.9,
            ]),
            'tubo-minimo' => $base([
                'boxModel' => BoxModel::Tube, 'widthMm' => 10.0, 'heightMm' => 10.0, 'quantity' => 1,
            ]),

            // Caixa gaveta: luva + gaveta, duas peças.
            'gaveta-padrao' => $base(['boxModel' => BoxModel::Drawer]),
            'gaveta-espessa' => $base([
                'boxModel' => BoxModel::Drawer,
                'material' => ['thickness_mm' => 7.0],
            ]),
            'gaveta-fracionada' => $base([
                'boxModel' => BoxModel::Drawer,
                'widthMm' => 87.3, 'heightMm' => 41.9, 'depthMm' => 132.6,
            ]),
            'gaveta-rasa' => $base([
                'boxModel' => BoxModel::Drawer,
                'widthMm' => 400.0, 'heightMm' => 20.0, 'depthMm' => 300.0,
            ]),
            // Mailer box: peça única die-cut, portada de mailer/mailer.py. Os
            // casos abaixo cobrem os dois lados de cada teto do layout — a aba
            // da parede (meia profundidade), a língua (altura da parede) e a
            // lingueta (largura da caixa) —, que é onde PHP e TS teriam como
            // divergir em silêncio.
            'mailer-padrao' => $base(['boxModel' => BoxModel::Mailer]),
            'mailer-espessa' => $base([
                'boxModel' => BoxModel::Mailer,
                'material' => ['thickness_mm' => 7.0],
            ]),
            // Proporção real de e-commerce: rasa e larga, nenhum teto pega.
            'mailer-rasa' => $base([
                'boxModel' => BoxModel::Mailer,
                'widthMm' => 320.0, 'heightMm' => 90.0, 'depthMm' => 300.0,
            ]),
            // Alta e estreita: a lingueta bate no teto da largura.
            'mailer-alta-e-rasa' => $base([
                'boxModel' => BoxModel::Mailer,
                'widthMm' => 300.0, 'heightMm' => 400.0, 'depthMm' => 80.0,
            ]),
            // Larga e rasa: quem governa a lingueta passa a ser a altura.
            'mailer-larga-e-rasa' => $base([
                'boxModel' => BoxModel::Mailer,
                'widthMm' => 400.0, 'heightMm' => 40.0, 'depthMm' => 150.0,
            ]),
            // Funda: abas e língua cabem inteiras, nenhum teto pega.
            'mailer-funda' => $base([
                'boxModel' => BoxModel::Mailer,
                'widthMm' => 300.0, 'heightMm' => 100.0, 'depthMm' => 400.0,
            ]),
            /*
             * Material grosso numa caixa pequena: é onde os tetos geométricos
             * do layout entram em ação (a língua para em hw − t, a barbatana
             * em tab − t). Sem um caso assim, um dos motores poderia clampar e
             * o outro não, e a divergência só apareceria num orçamento real.
             */
            'mailer-grossa-e-pequena' => $base([
                'boxModel' => BoxModel::Mailer,
                'material' => ['thickness_mm' => 7.0],
                'widthMm' => 120.0, 'heightMm' => 45.0, 'depthMm' => 90.0,
            ]),
            'mailer-fracionada' => $base([
                'boxModel' => BoxModel::Mailer,
                'widthMm' => 287.3, 'heightMm' => 141.9, 'depthMm' => 232.6,
            ]),
            // Medidas de tampa num modelo de tampa articulada: ignoradas.
            'mailer-tampa-ignorada' => $base([
                'boxModel' => BoxModel::Mailer,
                'lidWidthMm' => 999.0, 'lidHeightMm' => 999.0,
            ]),

            'tudo-combinado' => $base([
                'material' => ['cost_unit' => MaterialUnit::Kilogram, 'cost_per_unit' => 24.0, 'grammage_kg_per_m2' => 0.18, 'thickness_mm' => 0.6],
                'settings' => ['overhead_percent' => 12.0, 'tax_percent' => 8.0],
                'boxModel' => BoxModel::Tray,
                'widthMm' => 412.7, 'heightMm' => 233.1, 'depthMm' => 187.9,
                'quantity' => 7500, 'wastePercent' => 17.5,
                'productionMinutes' => 4.25, 'marginPercent' => 42.0,
                'pricingMode' => 'margin',
            ]),

            /*
             * Modelo livre: o único caminho que NÃO passa pelo BlankCalculator.
             *
             * Os casos abaixo cobrem o que os dois motores teriam como divergir
             * em silêncio — a separação estrutura/revestimento, a perda POR
             * PEÇA e a multiplicação da quantidade por caixa.
             */
            'livre-uma-peca' => $base([
                'boxModel' => BoxModel::Free,
                'customParts' => [$peca('structure', 3.20, 12.0, 300.0, 200.0, 1)],
            ]),

            // Duas perdas diferentes no mesmo orçamento: é o caso que denuncia
            // qualquer motor que aplique um percentual único.
            'livre-perdas-diferentes' => $base([
                'boxModel' => BoxModel::Free,
                'wastePercent' => 45.0, // do orçamento — deve ser IGNORADA nas peças
                'customParts' => [
                    $peca('structure', 3.20, 12.0, 300.0, 200.0, 2),
                    $peca('structure', 3.20, 8.0, 150.0, 200.0, 4),
                ],
            ]),

            // Estrutura e revestimento somam em linhas separadas da ficha.
            'livre-com-revestimento' => $base([
                'boxModel' => BoxModel::Free,
                'customParts' => [
                    $peca('structure', 3.20, 12.0, 300.0, 200.0, 1),
                    $peca('wrap', 22.50, 8.0, 330.0, 230.0, 1),
                ],
            ]),

            // Quantidade por caixa × lote: o erro de multiplicar uma vez só
            // apareceria aqui, e em nenhum outro caso.
            'livre-multiplas-por-caixa' => $base([
                'boxModel' => BoxModel::Free,
                'quantity' => 250,
                'customParts' => [
                    $peca('structure', 4.10, 12.0, 420.0, 310.0, 6),
                    $peca('wrap', 18.75, 15.0, 450.0, 340.0, 2),
                ],
            ]),

            /*
             * Peças com medida fracionada e material por quilo, no modo margin
             * e com imposto — o modelo livre atravessando todo o resto da
             * cadeia. Se a soma das áreas divergir num décimo de milímetro, é
             * aqui que o efeito aparece amplificado.
             */
            'livre-tudo-combinado' => $base([
                'boxModel' => BoxModel::Free,
                'settings' => ['overhead_percent' => 12.0, 'tax_percent' => 8.0],
                'quantity' => 1750,
                'productionMinutes' => 8.5,
                'marginPercent' => 38.0,
                'pricingMode' => 'margin',
                'customParts' => [
                    $peca('structure', 5.4321, 12.5, 287.3, 141.9, 3),
                    $peca('structure', 5.4321, 12.5, 91.7, 141.9, 2),
                    $peca('wrap', 28.3333, 9.75, 317.3, 171.9, 1),
                ],
            ]),

            // Uma peça mínima: exercita o arredondamento com área ínfima.
            'livre-peca-minima' => $base([
                'boxModel' => BoxModel::Free,
                'quantity' => 1,
                'customParts' => [$peca('structure', 3.20, 0.0, 1.0, 1.0, 1)],
            ]),
        ];

        /*
         * Um caso por modelo de caixa: cada um tem sua própria planificação.
         *
         * O modelo livre fica de fora porque ele não TEM planificação — sem
         * peças na entrada, os dois motores lançam exceção, que é justamente o
         * comportamento correto. Os casos dele estão acima, com peças.
         */
        foreach (BoxModel::cases() as $model) {
            if ($model->isFree()) {
                continue;
            }

            $scenarios["modelo-{$model->value}"] = $base(['boxModel' => $model]);
            $scenarios["modelo-{$model->value}-espesso"] = $base([
                'boxModel' => $model,
                'material' => ['thickness_mm' => 3.0],
            ]);
        }

        return $scenarios;
    }

    private function material(array $overrides = []): Material
    {
        return new Material([
            'name' => 'Fixture',
            'cost_unit' => MaterialUnit::SquareMeter,
            'cost_per_unit' => 3.20,
            'thickness_mm' => 0.0,
            ...$overrides,
        ]);
    }

    private function settings(array $overrides = []): CostSetting
    {
        return new CostSetting([
            'energy_tariff_per_kwh' => 0.92,
            'machine_hour_rate' => 45.00,
            'machine_power_kw' => 7.50,
            'labor_hour_rate' => 28.00,
            'overhead_percent' => 0.0,
            'tax_percent' => 0.0,
            'currency' => 'BRL',
            ...$overrides,
        ]);
    }
}

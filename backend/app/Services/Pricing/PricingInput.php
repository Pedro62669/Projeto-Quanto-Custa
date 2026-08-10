<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\BoxModel;
use App\Models\CostSetting;
use App\Models\Material;

/**
 * Entrada imutável do motor de precificação.
 *
 * Existe para que PricingEngine::calculate() receba UM argumento tipado em vez
 * de doze escalares posicionais — e para que qualquer campo novo (ex.: frete)
 * seja adicionado num único lugar, sem quebrar assinatura.
 */
final readonly class PricingInput
{
    public function __construct(
        public Material $material,
        public CostSetting $settings,
        public BoxModel $boxModel,
        public float $widthMm,
        public float $heightMm,
        public float $depthMm,
        public int $quantity,
        public float $wastePercent,
        public float $productionMinutesPerUnit,
        public float $profitMarginPercent,
        /** 'markup' => custo × (1+m) | 'margin' => custo ÷ (1−m) */
        public string $pricingMode = 'markup',

        /*
         * Medidas da tampa informadas pelo usuário, em mm.
         *
         * Null significa "usar a sugestão" — e cada eixo é independente, para
         * que dê para fixar só a altura da tampa e deixar largura e
         * profundidade acompanhando a base.
         */
        public ?float $lidWidthMm = null,
        public ?float $lidDepthMm = null,
        public ?float $lidHeightMm = null,

        /**
         * Custo do minuto da empresa, quando o modo hora-empresa está ligado.
         *
         * Chega pronto de fora porque o motor é PURO: calculá-lo aqui exigiria
         * ler `fixed_costs` e `equipment` do banco, e um motor que consulta
         * banco não pode ser testado em unidade nem espelhado em TypeScript.
         * Quem resolve é o controller — ver QuoteController::companyMinuteCost().
         *
         * Null = modo desligado; o motor usa `labor_hour_rate` e
         * `overhead_percent`, exatamente como sempre fez.
         */
        public ?float $companyMinuteCost = null,

        /**
         * Custo por m² do papel de revestimento — cartonagem rígida.
         *
         * Chega como NÚMERO e não como Material porque o motor tem gêmeo em
         * TypeScript, que recebe da API valores já normalizados. Passar o model
         * obrigaria o lado TS a reimplementar costPerSquareMeter() e a
         * conversão por gramatura — duas implementações da mesma regra, que é
         * exatamente o que a suíte de paridade existe para impedir.
         *
         * Null nos modelos dobrados, onde o material é um só.
         */
        public ?float $wrapCostPerM2 = null,

        /**
         * Ferragem da peça: ímãs, fechos, fitas. Cobrada por unidade.
         *
         * Lista e não campo único porque uma caixa ímã leva 4 ímãs E fita de
         * cetim, e o orçamento precisa mostrar as duas linhas separadas na
         * ficha técnica.
         *
         * @var list<array{cost_per_piece: float, quantity: float}>
         */
        public array $hardware = [],

        /**
         * Berço de acomodação, quando houver.
         *
         * Estrutura e não campos soltos porque o berço é uma escolha
         * COMPOSTA: o tipo decide a grandeza (área ou volume), e a grade só
         * existe num dos tipos. Cinco parâmetros soltos na assinatura fariam
         * quatro deles serem null na maior parte das chamadas.
         *
         * `cost_per_unit` é R$/m² ou R$/m³ conforme o tipo — já normalizado
         * pelo controller, como todo valor que chega ao motor.
         *
         * @var array{
         *     type: string,
         *     cost_per_unit: float,
         *     rows: int,
         *     columns: int,
         *     height_ratio: float,
         *     strip_thickness_mm: float
         * }|null
         */
        public ?array $cradle = null,
    ) {}

    /**
     * Constrói a entrada a partir do payload já validado da Request,
     * aplicando os defaults do material e das configurações vigentes.
     *
     * @param  array<string, mixed>  $data
     * @param  ?float  $companyMinuteCost  Custo do minuto da empresa, já
     *                                     calculado, ou null com o modo desligado.
     */
    public static function fromValidated(
        array $data,
        Material $material,
        CostSetting $settings,
        ?float $companyMinuteCost = null,
        ?float $wrapCostPerM2 = null,
        array $hardware = [],
        ?array $cradle = null,
    ): self {
        $boxModel = BoxModel::from($data['box_model'] ?? BoxModel::Rsc->value);

        return new self(
            material: $material,
            settings: $settings,
            boxModel: $boxModel,
            widthMm: (float) $data['width_mm'],
            heightMm: (float) $data['height_mm'],
            depthMm: (float) $data['depth_mm'],
            quantity: (int) ($data['quantity'] ?? 1),

            // Cada parâmetro opcional cai para o default da sua própria fonte:
            // desperdício vem do material, margem vem da configuração global.
            wastePercent: (float) ($data['waste_percent'] ?? $material->default_waste_percent),
            productionMinutesPerUnit: (float) (
                $data['production_minutes_per_unit'] ?? $boxModel->defaultProductionMinutes()
            ),
            profitMarginPercent: (float) (
                $data['profit_margin_percent'] ?? $settings->default_profit_margin_percent
            ),
            pricingMode: $data['pricing_mode'] ?? 'markup',

            // Ausente ou null => tampa automática.
            lidWidthMm: isset($data['lid_width_mm']) ? (float) $data['lid_width_mm'] : null,
            lidDepthMm: isset($data['lid_depth_mm']) ? (float) $data['lid_depth_mm'] : null,
            lidHeightMm: isset($data['lid_height_mm']) ? (float) $data['lid_height_mm'] : null,

            // Só vale com o modo ligado: assim ninguém liga a hora-empresa sem
            // querer por ter passado o número junto.
            companyMinuteCost: $settings->use_company_hour ? $companyMinuteCost : null,

            /*
             * O revestimento só vale onde a construção o comporta. Um RSC com
             * papel de revestimento selecionado cobraria uma operação que
             * ninguém vai executar — e o custo apareceria sem explicação na
             * ficha. A ferragem, ao contrário, passa em qualquer modelo: existe
             * caixa dobrada com fecho.
             */
            wrapCostPerM2: $boxModel->isRigid() ? $wrapCostPerM2 : null,
            hardware: $hardware,

            // O berço vale em qualquer modelo: caixa dobrada também acomoda.
            cradle: $cradle,
        );
    }
}

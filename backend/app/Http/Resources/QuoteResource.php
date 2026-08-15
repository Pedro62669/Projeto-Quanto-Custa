<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato de saída do orçamento.
 *
 * Existe para desacoplar o schema do banco do schema da API: renomear uma
 * coluna não deve quebrar o frontend. O snapshot completo só é exposto no
 * `show` — em listagens seria payload desperdiçado.
 */
class QuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,

            'client' => [
                /*
                 * O id do cadastro, quando o orçamento nasceu ligado a um.
                 *
                 * Null é a resposta honesta para a venda fechada com um nome e
                 * um WhatsApp — e é o que permite à tela oferecer "promover a
                 * cliente" só onde ela faz sentido.
                 */
                'id' => $this->client_id,

                // Texto congelado na emissão; ver QuoteController::store().
                'name' => $this->client_name,
                'email' => $this->client_email,
                'document' => $this->client_document,
            ],

            /*
             * A GEOMETRIA completa: o que faz a caixa ser aquela caixa.
             *
             * Devolvia só medidas e modelo, e era por isso que nenhum orçamento
             * salvo podia ser reaberto — faltavam a tampa, o berço e a lista de
             * materiais.
             *
             * Reabrir um orçamento junta TRÊS blocos desta resposta:
             * `specification`, `components` e `parameters`. Os nomes dos campos
             * são exatamente os que a API aceita na entrada, então a
             * reconstrução é um espalhamento e não uma tradução — que é o ponto,
             * porque tradução é onde os dois formatos divergem.
             *
             * Perda, minutos, margem e modo ficam em `parameters` porque não
             * descrevem a peça: a mesma caixa orçada com 20% ou 40% de margem
             * continua sendo a mesma caixa. A separação é a mesma que o
             * formulário faz na tela.
             */
            'specification' => [
                'material_id' => $this->material_id,
                'width_mm' => $this->width_mm,
                'height_mm' => $this->height_mm,
                'depth_mm' => $this->depth_mm,
                'box_model' => $this->box_model->value,
                'quantity' => $this->quantity,

                // Null = tampa automática, como o usuário informou.
                'lid_width_mm' => $this->lid_width_mm,
                'lid_depth_mm' => $this->lid_depth_mm,
                'lid_height_mm' => $this->lid_height_mm,

                'cradle_type' => $this->cradle_type?->value,
                'cradle_rows' => $this->cradle_rows,
                'cradle_columns' => $this->cradle_columns,
                'cradle_height_ratio' => $this->cradle_height_ratio,

                'material' => $this->whenLoaded('material', fn () => [
                    'id' => $this->material->id,
                    'name' => $this->material->name,
                    'color_hex' => $this->material->color_hex,
                ]),
            ],

            /*
             * A lista de materiais no formato que `components` aceita na
             * entrada — revestimento incluído.
             *
             * Aqui ele volta a ser uma linha como as outras, apesar de morar em
             * coluna própria: o consumidor desta chave é o formulário, e para
             * ele revestimento é um item da lista como ímã. A assimetria de
             * armazenamento (ver a migration de `quote_components`) existe por
             * cardinalidade e não precisa vazar para quem só quer reabrir.
             *
             * `whenLoaded` porque a listagem não carrega a relação: cinquenta
             * orçamentos com componentes seria payload desperdiçado, e o índice
             * nem oferece o botão de duplicar.
             */
            'components' => $this->whenLoaded('components', fn () => collect()
                ->when($this->wrap_material_id !== null, fn ($lista) => $lista->push([
                    'material_id' => $this->wrap_material_id,
                    'role' => 'wrap',
                    'quantity' => null,
                ]))
                ->concat($this->components->map(fn ($c) => [
                    'material_id' => $c->material_id,
                    'role' => $c->component_role->value,
                    'quantity' => $c->quantity,
                ]))
                ->values()
                ->all()),

            /** As peças do modelo livre, editáveis — o snapshot é fotografia. */
            'custom_parts' => $this->whenLoaded('customParts', fn () => $this->customParts
                ->map(fn ($p) => [
                    'material_id' => $p->material_id,
                    'name' => $p->name,
                    'role' => $p->component_role->value,
                    'width_mm' => $p->width_mm,
                    'length_mm' => $p->length_mm,
                    'quantity' => $p->quantity,
                ])
                ->all()),

            'parameters' => [
                'waste_percent' => $this->waste_percent,
                'production_minutes_per_unit' => $this->production_minutes_per_unit,
                'profit_margin_percent' => $this->profit_margin_percent,
                'pricing_mode' => $this->pricing_mode,
            ],

            'costs' => [
                'material' => $this->material_cost,

                // Lista de materiais: zero fora da cartonagem rígida e sem
                // ferragem. Vão sempre presentes para que a ficha técnica não
                // precise testar a existência da chave antes de somar.
                'wrap' => $this->wrap_cost,
                'hardware' => $this->hardware_cost,

                'labor' => $this->labor_cost,
                'machine' => $this->machine_cost,
                'energy' => $this->energy_cost,
                'overhead' => $this->overhead_cost,
                'unit_cost' => $this->unit_cost,
            ],

            'pricing' => [
                'unit_price' => $this->unit_price,
                'total_cost' => $this->total_cost,
                'total_price' => $this->total_price,
                'profit_amount' => $this->profit_amount,
            ],

            'area' => [
                'per_unit_m2' => $this->area_m2_per_unit,
                'total_m2' => $this->area_m2_total,
            ],

            'snapshot' => $this->when($request->routeIs('*.show'), $this->pricing_snapshot),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

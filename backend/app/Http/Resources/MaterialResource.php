<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin() ?? false;

        /*
         * Nem todo material tem custo por m².
         *
         * Ímã é contado, espuma é comprada em bloco — e `costPerSquareMeter()`
         * RECUSA convertê-los, com razão. Chamar o método em todo material fazia
         * GET /materials devolver erro assim que a empresa cadastrasse uma
         * ferragem: a lista inteira caía por causa de uma linha, e com ela a
         * calculadora, que não abre sem materiais.
         *
         * Null e não zero. Zero é um custo, e um custo de zero precifica a caixa
         * como se o material fosse de graça — o tipo de erro que sai plausível.
         */
        $temArea = $this->cost_unit->isAreaBased();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'description' => $this->description,

            // O usuário comum precisa do custo já normalizado para simular
            // preços, mas não do preço de compra bruto nem da unidade de
            // negociação com o fornecedor — isso é informação de admin.
            'cost_per_m2' => $temArea ? round($this->costPerSquareMeter(), 4) : null,

            /*
             * A bandeira que a interface consome para não oferecer um ímã onde
             * se pede uma peça medida em milímetros. Não é dado sensível — diz
             * "un" ou "m²", não quanto a empresa paga —, então vai para todos.
             */
            'is_area_based' => $temArea,
            $this->mergeWhen($isAdmin, [
                'cost_unit' => $this->cost_unit->value,
                'cost_per_unit' => $this->cost_per_unit,
                'grammage_kg_per_m2' => $this->grammage_kg_per_m2,

                /*
                 * O lote de compra — informação de negociação, como o preço
                 * unitário logo acima.
                 *
                 * Estava AUSENTE do recurso enquanto o CRUD de admin o aceitava
                 * na escrita, e a assimetria é destrutiva: um formulário de
                 * edição lê o que a API devolve, e o que ela não devolve chega
                 * vazio ao usuário. Salvar sem tocar nesses campos apagava o
                 * lote cadastrado — silenciosamente, e com o custo por m²
                 * mudando junto, porque o lote tem precedência no cálculo.
                 *
                 * A regra que essa falha ensina: um endpoint que ACEITA um
                 * campo precisa DEVOLVÊ-LO.
                 */
                'lot_quantity' => $this->lot_quantity,
                'lot_purchase_cost' => $this->lot_purchase_cost,
                'lot_freight_cost' => $this->lot_freight_cost,
            ]),

            'default_waste_percent' => $this->default_waste_percent,
            'thickness_mm' => $this->thickness_mm,

            /*
             * Formato da folha e sentido da fibra.
             *
             * Vão para todos, e não só para o admin: não dizem quanto a empresa
             * paga, dizem como o material se corta. É deles que sai o plano de
             * corte da ficha técnica — sem a medida da folha, o arranjo não
             * existe e a perda real fica desconhecida.
             */
            'sheet_width_mm' => $this->sheet_width_mm,
            'sheet_length_mm' => $this->sheet_length_mm,
            'grain_direction' => $this->grain_direction->value,

            // Consumido diretamente pelo renderizador 3D.
            'color_hex' => $this->color_hex,
            'texture_url' => $this->texture_url,

            'is_active' => $this->is_active,
        ];
    }
}

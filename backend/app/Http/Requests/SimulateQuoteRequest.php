<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\BoxModel;
use App\Enums\ComponentRole;
use App\Enums\CradleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação da simulação (cálculo sem persistir).
 *
 * Repare no que NÃO está aqui: nenhum campo de custo ou preço. O cliente envia
 * apenas a especificação; todo valor monetário é derivado no servidor. Aceitar
 * um preço vindo do frontend seria confiar no navegador para definir quanto a
 * empresa cobra.
 */
class SimulateQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'material_id' => ['required', 'integer', Rule::exists('materials', 'id')->where('is_active', true)],

            // Limites físicos: abaixo de 10mm não há caixa; acima de 3m não há
            // chapa. Faixas explícitas evitam áreas absurdas e overflow no 3D.
            'width_mm' => ['required', 'numeric', 'min:10', 'max:3000'],
            'height_mm' => ['required', 'numeric', 'min:10', 'max:3000'],
            'depth_mm' => ['required', 'numeric', 'min:10', 'max:3000'],

            'box_model' => ['nullable', Rule::enum(BoxModel::class)],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],

            'waste_percent' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'production_minutes_per_unit' => ['nullable', 'numeric', 'min:0', 'max:600'],
            'profit_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'pricing_mode' => ['nullable', Rule::in(['markup', 'margin'])],

            /*
             * Medidas da tampa. Omitir (ou enviar null) mantém o cálculo
             * automático a partir da base.
             *
             * gte na largura e na profundidade porque a tampa encaixa POR
             * FORA: uma tampa menor que a base é fisicamente impossível de
             * fechar, e aceitar isso produziria um orçamento de uma peça que
             * não existe. A altura é livre — tampa rasa ou funda é escolha
             * legítima de projeto.
             */
            'lid_width_mm' => ['nullable', 'numeric', 'min:10', 'max:3200', 'gte:width_mm'],
            'lid_depth_mm' => ['nullable', 'numeric', 'min:10', 'max:3200', 'gte:depth_mm'],
            'lid_height_mm' => ['nullable', 'numeric', 'min:1', 'max:3000'],

            /*
             * Lista de materiais — cartonagem rígida e ferragem.
             *
             * `material_id` acima continua sendo a ESTRUTURA (o papelão cinza),
             * e não virou uma linha da lista: manter o campo onde estava é o
             * que permite que todo orçamento, formulário e teste anterior
             * continuem válidos sem uma linha de mudança.
             *
             * A quantidade só faz sentido em ferragem — revestimento é área, e
             * pedir "quantos revestimentos" seria uma pergunta sem resposta.
             */
            'components' => ['nullable', 'array', 'max:20'],
            'components.*.material_id' => [
                'required', 'integer',
                Rule::exists('materials', 'id')->where('is_active', true),
            ],
            'components.*.role' => ['required', Rule::enum(ComponentRole::class)],
            'components.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:10000'],

            /*
             * Parâmetros do berço, quando houver um componente com papel
             * `cradle`. Vivem fora da lista porque descrevem a CONSTRUÇÃO, não
             * o material: a mesma espuma serve a berços de alturas diferentes,
             * e a grade não é propriedade do papelão.
             */
            'cradle_type' => ['nullable', Rule::enum(CradleType::class)],
            'cradle_rows' => ['nullable', 'integer', 'min:1', 'max:20'],
            'cradle_columns' => ['nullable', 'integer', 'min:1', 'max:20'],

            // Fração da altura interna. Abaixo de 10% o berço não segura nada;
            // acima de 100% ele não cabe na caixa.
            'cradle_height_ratio' => ['nullable', 'numeric', 'min:0.1', 'max:1'],

            /*
             * Peças medidas à mão — modelo livre.
             *
             * `required` quando o modelo é `free`: sem peça não há caixa, e um
             * modelo livre vazio produziria um orçamento só de mão de obra —
             * um preço que parece calculado e não descreve nada. O motor também
             * recusa (DomainException), mas errar aqui devolve o campo exato ao
             * formulário em vez de uma mensagem geral.
             *
             * Nos outros modelos as peças são simplesmente descartadas: ver
             * PricingInput::fromValidated().
             */
            'custom_parts' => [
                Rule::requiredIf(fn () => $this->input('box_model') === BoxModel::Free->value),
                'array', 'max:60',
            ],
            'custom_parts.*.material_id' => [
                'required', 'integer',
                Rule::exists('materials', 'id')->where('is_active', true),
            ],
            'custom_parts.*.name' => ['required', 'string', 'max:120'],

            /*
             * Só estrutura e revestimento. Ferragem e berço continuam em
             * `components`, onde já funcionam para qualquer modelo — ímã é
             * contado, não medido, e berço tem parâmetros de construção
             * próprios. Aceitá-los aqui criaria um segundo caminho para o mesmo
             * custo, e os dois divergiriam.
             */
            'custom_parts.*.role' => ['nullable', Rule::in([
                ComponentRole::Structure->value,
                ComponentRole::Wrap->value,
            ])],

            // Mesmos limites físicos das dimensões da caixa: abaixo de 1mm não
            // se corta, acima de 3m não há chapa.
            'custom_parts.*.width_mm' => ['required', 'integer', 'min:1', 'max:3000'],
            'custom_parts.*.length_mm' => ['required', 'integer', 'min:1', 'max:3000'],

            // Por CAIXA, não pelo lote — ver a migration.
            'custom_parts.*.quantity' => ['required', 'integer', 'min:1', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'material_id.exists' => 'A matéria-prima selecionada não existe ou está inativa.',
            'components.*.material_id.exists' => 'Um dos materiais da lista não existe ou está inativo.',
            'components.*.role.enum' => 'O papel do material deve ser estrutura, revestimento ou ferragem.',
            'custom_parts.required' => 'O modelo livre precisa de ao menos uma peça. '
                .'Informe as chapas e folhas que serão cortadas, com medida e quantidade.',
            'custom_parts.*.material_id.exists' => 'Uma das peças aponta para um material que não existe ou está inativo.',
            'custom_parts.*.role.in' => 'A peça só pode ser estrutura ou revestimento. '
                .'Ferragem e berço entram na lista de materiais, não nas peças.',
            'custom_parts.*.name.required' => 'Dê um nome a cada peça — é por ele que a produção vai identificá-la.',
            'lid_width_mm.gte' => 'A tampa encaixa por fora: sua largura não pode ser menor que a da caixa.',
            'lid_depth_mm.gte' => 'A tampa encaixa por fora: sua profundidade não pode ser menor que a da caixa.',
            '*.min' => 'O campo :attribute está abaixo do mínimo permitido.',
            '*.max' => 'O campo :attribute excede o máximo permitido.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'width_mm' => 'largura',
            'height_mm' => 'altura',
            'depth_mm' => 'profundidade',
        ];
    }

    protected function prepareForValidation(): void
    {
        // O modo 'margin' com 100% quebra a fórmula (divisão por zero). O motor
        // também protege, mas rejeitar na borda dá erro 422 legível em vez de 500.
        if ($this->input('pricing_mode') === 'margin') {
            $this->merge(['profit_margin_percent' => min((float) $this->input('profit_margin_percent', 0), 99.0)]);
        }
    }
}

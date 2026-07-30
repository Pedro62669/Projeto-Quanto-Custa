<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\BoxModel;
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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'material_id.exists' => 'A matéria-prima selecionada não existe ou está inativa.',
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

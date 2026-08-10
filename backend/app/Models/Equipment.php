<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma máquina do parque produtivo.
 *
 * @property float $purchase_value
 * @property int $useful_life_months
 */
class Equipment extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /**
     * "equipment" é incontável em inglês, então o pluralizador do Eloquent
     * devolveria o mesmo nome — mas declarar remove a dúvida de quem lê, e
     * protege de uma futura mudança no inflector.
     */
    protected $table = 'equipment';

    /** Ver CostSetting: o `creating` da trait sobrescreve tenant_id vindo de request. */
    protected $fillable = ['tenant_id', 'name', 'purchase_value', 'useful_life_months'];

    /**
     * As duas grandezas derivadas acompanham toda serialização.
     *
     * São `appends`, e não colunas, de propósito: guardar a depreciação no
     * banco criaria dois lugares onde a mesma verdade pode divergir — bastaria
     * alguém corrigir a vida útil sem recalcular a coluna. Derivar na leitura
     * custa uma divisão e não tem como ficar desatualizado.
     *
     * @var list<string>
     */
    protected $appends = ['monthly_depreciation', 'annual_depreciation'];

    protected function casts(): array
    {
        return [
            'purchase_value' => 'float',
            'useful_life_months' => 'integer',
        ];
    }

    /**
     * Quanto a máquina consome de valor por mês.
     *
     * Linear (valor ÷ vida útil), que é o método que o usuário do sistema
     * consegue conferir de cabeça. Métodos acelerados existem na contabilidade,
     * mas aqui a finalidade é formar preço: previsibilidade vale mais que
     * fidelidade fiscal, e uma parcela que muda todo mês tornaria o custo da
     * peça impossível de explicar ao cliente.
     */
    public function getMonthlyDepreciationAttribute(): float
    {
        /*
         * Guarda de integridade, não de interface. A Request já barra vida útil
         * zero; isto cobre o registro que chegou por seeder, import ou console.
         * Devolver 0.0 em vez de estourar divisão por zero é a escolha certa
         * AQUI: um inventário com uma linha defeituosa não pode derrubar a
         * listagem inteira nem a simulação de preço.
         */
        if ($this->useful_life_months <= 0) {
            return 0.0;
        }

        return round($this->purchase_value / $this->useful_life_months, 2);
    }

    /**
     * A mesma parcela vista no horizonte de um ano.
     *
     * Deriva da mensal já arredondada, e não do valor cheio, para que as duas
     * fechem entre si: um usuário que multiplicar a mensal por 12 na calculadora
     * precisa chegar exatamente no número que a tela mostra como anual.
     */
    public function getAnnualDepreciationAttribute(): float
    {
        return round($this->monthly_depreciation * 12, 2);
    }
}

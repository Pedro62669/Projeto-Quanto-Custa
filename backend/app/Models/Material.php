<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property MaterialType $type
 * @property MaterialUnit $cost_unit
 */
class Material extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /**
     * `tenant_id` é preenchível pelo mesmo motivo que em CostSetting: o
     * `creating` da trait sobrescreve o campo depois do fill(), neutralizando
     * qualquer valor vindo de request autenticado. Serve a quem grava sem
     * usuário logado — seeder e factory.
     */
    protected $fillable = [
        'tenant_id',
        'name', 'type', 'description',
        'cost_unit', 'cost_per_unit', 'grammage_kg_per_m2',
        'default_waste_percent', 'thickness_mm',
        'color_hex', 'texture_url', 'is_active',
    ];

    /**
     * Espelha os defaults do banco no model — mesma razão que em User: um
     * Material criado sem `default_waste_percent` carregaria null em memória e
     * o motor calcularia com 0% de desperdício, subestimando o custo.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'default_waste_percent' => 10.00,
        'color_hex' => '#C8A06A',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => MaterialType::class,
            'cost_unit' => MaterialUnit::class,
            'cost_per_unit' => 'float',
            'grammage_kg_per_m2' => 'float',
            'default_waste_percent' => 'float',
            'thickness_mm' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Normaliza o custo para R$/m², que é o denominador comum do motor de cálculo.
     *
     * Materiais vendidos por kg são convertidos pela gramatura:
     *   R$/m² = R$/kg × kg/m²
     *
     * Ex.: papelão a R$ 4,50/kg com 0,300 kg/m² => R$ 1,35/m².
     */
    public function costPerSquareMeter(): float
    {
        /*
         * Ferragem não tem área. Um ímã de 6×2mm tem R$/m² astronômico e sem
         * significado nenhum — o consumo dele é contado, não medido. Recusar
         * aqui evita que um material cadastrado na unidade errada entre no
         * cálculo de área e produza um preço absurdo sem ninguém perceber.
         */
        if (! $this->cost_unit->isAreaBased()) {
            throw new \DomainException(
                "Material #{$this->id} ({$this->name}) é cotado por peça e não tem custo por m². "
                .'Use-o como ferragem na lista de materiais.'
            );
        }

        if ($this->cost_unit === MaterialUnit::SquareMeter) {
            return $this->cost_per_unit;
        }

        // Guarda de integridade: a Request valida isso na entrada, mas um
        // material importado por seeder/script poderia chegar inconsistente.
        if (! $this->grammage_kg_per_m2) {
            throw new \DomainException(
                "Material #{$this->id} ({$this->name}) é cotado em kg mas não possui gramatura cadastrada."
            );
        }

        return $this->cost_per_unit * $this->grammage_kg_per_m2;
    }

    /**
     * Custo de UMA peça de ferragem.
     *
     * Espelho de costPerSquareMeter() para o outro regime de cobrança: aqui o
     * preço de compra já É o custo unitário, sem conversão. O método existe
     * mesmo sendo trivial para que o motor nunca leia `cost_per_unit` cru — é
     * a leitura crua que faz alguém multiplicar preço de quilo por quantidade
     * de peças sem notar.
     */
    public function costPerPiece(): float
    {
        if ($this->cost_unit->isAreaBased()) {
            throw new \DomainException(
                "Material #{$this->id} ({$this->name}) é cotado por área e não pode ser contado por peça."
            );
        }

        return $this->cost_per_unit;
    }

    /** Custo de UM metro cúbico — espuma e EVA, vendidos em bloco. */
    public function costPerCubicMeter(): float
    {
        if (! $this->cost_unit->isVolumetric()) {
            throw new \DomainException(
                "Material #{$this->id} ({$this->name}) não é cotado por metro cúbico. "
                .'Berço em espuma exige material comprado em bloco.'
            );
        }

        return $this->cost_per_unit;
    }
}

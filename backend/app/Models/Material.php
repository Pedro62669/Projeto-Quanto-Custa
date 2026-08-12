<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GrainDirection;
use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property GrainDirection $grain_direction
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
        'sheet_width_mm', 'sheet_length_mm',
        'lot_quantity', 'lot_purchase_cost', 'lot_freight_cost',
        'default_waste_percent', 'thickness_mm', 'grain_direction',
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
        'grain_direction' => GrainDirection::None->value,
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
            'sheet_width_mm' => 'integer',
            'sheet_length_mm' => 'integer',
            'lot_quantity' => 'integer',
            'lot_purchase_cost' => 'float',
            'lot_freight_cost' => 'float',
            'default_waste_percent' => 'float',
            'thickness_mm' => 'float',
            'grain_direction' => GrainDirection::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * A chave do pivô nunca vai para a resposta.
     *
     * Quando o material chega carregado por `Supplier::materials()`, o Eloquent
     * anexa um objeto `pivot` a cada linha. Aqui ele não carrega informação
     * nenhuma — a tabela só tem as duas chaves estrangeiras —, e o que ele faz é
     * poluir o JSON de toda listagem de fornecedor com um campo que a interface
     * ignora e que a próxima pessoa vai tentar entender.
     */
    protected $hidden = ['pivot'];

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /** Quem vende este material. O outro lado de Supplier::materials(). */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class)->orderBy('suppliers.name');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Custo de UM item do lote comprado, já com o frete rateado.
     *
     *   (valor da nota + frete) ÷ quantidade de itens
     *
     * Devolve null quando o cadastro não descreve um lote — e é esse null que
     * mantém compatível todo material cadastrado com R$/m² direto. Não é um
     * fallback preguiçoso: é a única forma de a mudança entrar sem obrigar a
     * empresa a recadastrar o estoque inteiro antes de voltar a orçar.
     *
     * O frete entra aqui e em nenhum outro lugar. Antes disso ele era invisível:
     * quem paga R$ 400 de entrega numa carga de chapas não via esse dinheiro no
     * preço da caixa, e no fim do mês o caixa não fechava com a margem que a
     * planilha prometia.
     */
    public function lotUnitCost(): ?float
    {
        if ($this->lot_purchase_cost === null || $this->lot_quantity === null) {
            return null;
        }

        // Lote de zero itens não é lote — e a divisão estouraria. A validação da
        // Request já barra, mas um seeder ou importação poderia chegar assim.
        if ($this->lot_quantity < 1) {
            return null;
        }

        // Frete ausente é frete zero (retirada no fornecedor), não cadastro
        // incompleto: exigi-lo obrigaria a digitar 0 sem necessidade.
        $total = $this->lot_purchase_cost + ($this->lot_freight_cost ?? 0.0);

        return $total / $this->lot_quantity;
    }

    /** Área da folha comprada, em m². Null quando as medidas não estão cadastradas. */
    public function sheetAreaM2(): ?float
    {
        if (! $this->sheet_width_mm || ! $this->sheet_length_mm) {
            return null;
        }

        return ($this->sheet_width_mm * $this->sheet_length_mm) / 1_000_000.0;
    }

    /**
     * Normaliza o custo para R$/m², que é o denominador comum do motor de cálculo.
     *
     * Três caminhos, nesta ordem de precedência:
     *
     *  1. LOTE COM FRETE, quando valor, quantidade e medida da folha estão
     *     cadastrados. É o mais fiel: sai de onde o dinheiro efetivamente saiu.
     *  2. R$/m² direto.
     *  3. R$/kg convertido pela gramatura — R$/m² = R$/kg × kg/m².
     *     Ex.: papelão a R$ 4,50/kg com 0,300 kg/m² => R$ 1,35/m².
     *
     * O lote vem primeiro porque, quando existe, os outros dois são cópias
     * envelhecidas dele: `cost_per_unit` foi digitado numa compra anterior e não
     * acompanha a nota nova.
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

        $custoDoItem = $this->lotUnitCost();
        $areaDaFolha = $this->sheetAreaM2();

        if ($custoDoItem !== null && $areaDaFolha !== null && $areaDaFolha > 0) {
            return $custoDoItem / $areaDaFolha;
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

        /*
         * Ferragem também vem em lote, e também tem frete: uma caixa de mil ímãs
         * com R$ 60 de entrega custa mais do que a nota diz. Aqui não é preciso
         * medida de folha — o item já É a unidade cobrada.
         */
        return $this->lotUnitCost() ?? $this->cost_per_unit;
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

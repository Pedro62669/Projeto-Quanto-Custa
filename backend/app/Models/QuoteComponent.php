<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComponentRole;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um insumo que não se mede em milímetros: ferragem ou berço.
 *
 * O que ela NÃO guarda: preço. Mesma razão de `QuoteCustomPart` — o custo vem
 * do material apontado, e congelá-lo aqui faria a linha envelhecer com números
 * que o cadastro já corrigiu. O congelamento acontece uma vez só, no
 * `pricing_snapshot`, que é onde ele significa alguma coisa: ali o preço foi
 * combinado com o cliente.
 *
 * Estrutura e revestimento não passam por aqui — ver a migration.
 *
 * @property ComponentRole $component_role
 */
class QuoteComponent extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'quote_id', 'material_id', 'component_role', 'quantity',
    ];

    protected function casts(): array
    {
        return [
            'component_role' => ComponentRole::class,
            'quantity' => 'float',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}

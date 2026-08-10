<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A empresa assinante.
 *
 * Não usa BelongsToTenant: ela É o tenant. Quem lê esta tabela é o admin de
 * plataforma (gestão de assinaturas) ou o próprio dono lendo o perfil da sua
 * empresa — e esse segundo caso passa pela relação a partir do usuário
 * autenticado, que já é o vínculo correto.
 */
class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'legal_name', 'document',
        'email', 'whatsapp', 'phone',
        'instagram', 'tiktok', 'facebook', 'website',
        'postal_code', 'street', 'street_number', 'complement',
        'district', 'city', 'state',
        'logo_path', 'is_active',
    ];

    /**
     * Campos de plano FORA do fillable, e de propósito.
     *
     * `plan_type` e os `max_*` são o que o assinante pagaria para mudar. Deixá-los
     * preenchíveis em massa significa que um `update($request->all())` em
     * qualquer tela de perfil da empresa vira um upgrade grátis. Quem muda plano
     * é o gateway (via webhook) ou o admin de plataforma — os dois por caminho
     * explícito, com forceFill ou atribuição direta.
     */
    protected $attributes = [
        'is_active' => true,
        'plan_type' => PlanType::Free->value,
        'plan_status' => PlanStatus::Trialing->value,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'plan_type' => PlanType::class,
            'plan_status' => PlanStatus::class,
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function costSettings(): HasMany
    {
        return $this->hasMany(CostSetting::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /**
     * A assinatura vigente — a mais recente que não foi cancelada.
     *
     * Ordena por `started_at` e não por id: um reprocessamento de webhook antigo
     * pode inserir uma linha nova referente a um contrato velho, e o id maior
     * apontaria para a assinatura errada.
     */
    public function assinaturaVigente(): ?Subscription
    {
        return $this->subscriptions()
            ->whereNot('status', PlanStatus::Canceled)
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * O plano que vale AGORA, para efeito de cota.
     *
     * Diferente de `plan_type`, que é o plano CONTRATADO. Os dois divergem numa
     * única situação, e ela é frequente: o período de teste acabou e o cron
     * ainda não passou. Durante essa janela a coluna diz "Pro" e a verdade é
     * "gratuito".
     *
     * Este método é a fonte da verdade na hora de aplicar limite — ver
     * QuotaGuard::limite(). O EncerraTestesExpirados materializa a mesma
     * conclusão na coluna, mas por outro motivo: os agregados do painel de
     * plataforma são SQL e não conseguem chamar método PHP. Método para
     * decidir, coluna para somar.
     *
     * Repare que teste vencido NÃO bloqueia nada: só rebaixa. Quem estava
     * avaliando o produto não é inadimplente, e prender os dados de quem ainda
     * não pagou nada seria a pior primeira impressão possível.
     */
    public function planoVigente(): PlanType
    {
        $testeAcabou = $this->plan_status === PlanStatus::Trialing
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast();

        return $testeAcabou ? PlanType::Free : $this->plan_type;
    }

    /**
     * O acesso ao sistema está liberado?
     *
     * Três portas, e a ordem importa. `is_active` é decisão administrativa e
     * vence tudo — ver PlanStatus para o motivo de não ser o mesmo campo. Depois
     * vem a validade paga; e quem nunca teve assinatura (gratuito) passa, porque
     * o plano Free é um plano, não uma ausência de plano.
     */
    public function acessoLiberado(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->subscription_ends_at !== null) {
            return $this->subscription_ends_at->isFuture();
        }

        return true;
    }

    /**
     * Purga os dados da empresa na ordem que as chaves estrangeiras exigem.
     *
     * O banco sozinho não dá conta. `quotes.material_id` é restrictOnDelete —
     * de propósito, para que ninguém apague uma matéria-prima já usada em
     * proposta emitida. Só que a cascata de `tenants` tenta apagar materiais e
     * orçamentos sem ordem garantida, e esbarra nesse restrict: excluir a
     * empresa falhava com violação de integridade.
     *
     * Apagar os orçamentos primeiro resolve, e resolve melhor do que afrouxar o
     * restrict: a proteção da matéria-prima continua valendo no caso do dia a
     * dia, e a exclusão total vira um caminho explícito e auditável — que é
     * exatamente o que o direito ao esquecimento da LGPD vai cobrar.
     *
     * forceDelete nos orçamentos: eles usam SoftDeletes, e "excluir a conta"
     * não pode deixar para trás linhas marcadas como apagadas.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $tenant): void {
            DB::transaction(function () use ($tenant): void {
                Quote::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->withTrashed()
                    ->where('tenant_id', $tenant->id)
                    ->forceDelete();

                Material::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->where('tenant_id', $tenant->id)
                    ->delete();

                CostSetting::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->where('tenant_id', $tenant->id)
                    ->delete();
            });
        });
    }
}

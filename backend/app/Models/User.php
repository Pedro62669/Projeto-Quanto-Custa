<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property UserRole $role
 * @property ?int $tenant_id
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Espelha os defaults do banco no model.
     *
     * Sem isto, um User recém-criado sem `is_active` explícito carrega null em
     * memória (o default só é aplicado pelo banco, e create() não relê a linha).
     * O middleware de admin checa `! $user->is_active` e barraria um admin
     * legítimo no mesmo request em que ele foi criado.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => UserRole::User->value,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'marketing_opt_out_at' => 'datetime',
        ];
    }

    /**
     * Aceita receber e-mail de engajamento?
     *
     * Fora do $fillable e lido por método: a oposição do titular (LGPD art. 18,
     * §2º) não pode ser revogada por um update em massa vindo de um formulário
     * de perfil qualquer.
     */
    public function aceitaEngajamento(): bool
    {
        return $this->marketing_opt_out_at === null;
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function engagementEmails(): HasMany
    {
        return $this->hasMany(EngagementEmail::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Admin de plataforma: quem opera o SaaS, sem vínculo com empresa alguma.
     *
     * É a diferença que o multi-inquilino introduz em `isAdmin()`. Antes havia
     * um só tipo de admin, dono do sistema inteiro. Agora "admin" quase sempre
     * significa dono de UMA empresa, com poder total lá dentro e nenhum fora —
     * e é o tenant_id nulo, não o papel, que distingue os dois.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->isAdmin() && $this->tenant_id === null;
    }
}

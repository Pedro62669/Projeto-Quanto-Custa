<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;

/**
 * Registro de acesso — append-only e assinado.
 *
 * Não usa BelongsToTenant de propósito. A trait escoparia a leitura pelo
 * usuário logado, e este é o único lugar do sistema onde isso seria errado:
 * o registro precisa continuar existindo e legível para a plataforma depois
 * que a empresa some, que é o que a guarda do Marco Civil exige. O filtro por
 * tenant, quando fizer sentido (a empresa lendo o próprio histórico), é feito
 * explicitamente por quem consulta — ver scopeDaEmpresa().
 *
 * @property ?int $user_id
 * @property ?int $tenant_id
 */
class AccessLog extends Model
{
    use HasFactory;

    /** A tabela não tem updated_at: uma linha nasce e não muda. */
    public $timestamps = false;

    protected $guarded = ['id', 'signature'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'status_code' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Campos que entram na assinatura, na ordem.
     *
     * `user_id` e `tenant_id` ficam DE FORA, e isso é o que faz a assinatura
     * sobreviver ao direito ao esquecimento: a exclusão da conta anula os dois
     * (nullOnDelete), e assinar sobre eles invalidaria todo o histórico do
     * titular no exato momento em que a lei manda preservá-lo. Quem identifica
     * o agente na assinatura é o `subject_hash`, que não é anulado.
     */
    private const CAMPOS_ASSINADOS = [
        'subject_hash', 'ip_address', 'user_agent',
        'method', 'path', 'route_name', 'status_code', 'event', 'occurred_at',
    ];

    /**
     * HMAC-SHA256 do conteúdo do evento com a APP_KEY.
     *
     * Não é criptografia: qualquer um lê a linha. É prova de autoria — sem a
     * chave da aplicação não se produz a assinatura de um conteúdo alterado,
     * então editar um IP no banco deixa rastro verificável.
     *
     * @param  array<string, mixed>  $atributos
     */
    public static function assinatura(array $atributos): string
    {
        $conteudo = [];

        foreach (self::CAMPOS_ASSINADOS as $campo) {
            $valor = $atributos[$campo] ?? null;

            $conteudo[] = $valor instanceof \DateTimeInterface
                ? $valor->format('Y-m-d H:i:s')
                : (string) $valor;
        }

        // "\0" como separador: não aparece em nenhum dos campos, então dois
        // conjuntos de valores diferentes não podem produzir a mesma string
        // concatenada (o que permitiria forjar um registro sem a chave).
        return hash_hmac('sha256', implode("\0", $conteudo), self::chave());
    }

    /** Pseudônimo estável do titular; sem a chave não volta ao id. */
    public static function hashDoSujeito(?int $userId): ?string
    {
        return $userId === null
            ? null
            : hash_hmac('sha256', 'user:'.$userId, self::chave());
    }

    private static function chave(): string
    {
        $key = (string) Config::get('app.key');

        // A APP_KEY vem em base64: usar a chave decodificada mantém a
        // assinatura estável mesmo se a representação textual mudar.
        return str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true) ?: $key
            : $key;
    }

    /** A assinatura gravada confere com o conteúdo atual da linha? */
    public function integro(): bool
    {
        return hash_equals(
            (string) $this->signature,
            self::assinatura($this->getAttributes()),
        );
    }

    public function scopeDaEmpresa(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    protected static function booted(): void
    {
        /*
         * Assina no `creating`, com os atributos já montados. Fazer isso aqui,
         * e não em quem chama, garante que não existe caminho de escrita capaz
         * de gravar um registro sem assinatura — inclusive um seeder ou um
         * console que alguém escreva depois.
         */
        static::creating(function (self $log): void {
            $log->occurred_at ??= now();
            $log->signature = self::assinatura($log->getAttributes());
        });

        /*
         * Append-only imposto na aplicação.
         *
         * O banco não tem como recusar um UPDATE sem trigger, e trigger amarra
         * o schema a um driver. Barrar aqui cobre todo caminho que passe pelo
         * Eloquent, que é todo caminho da aplicação; a assinatura cobre o resto
         * (quem for direto ao SQL não consegue reassinar).
         */
        static::updating(function (): never {
            throw new \LogicException(
                'Registro de acesso é imutável: a guarda do Marco Civil exige que não seja alterado.'
            );
        });

        static::deleting(function (self $log): never {
            throw new \LogicException(
                'Registro de acesso não pode ser excluído individualmente. '
                .'O expurgo é feito por retenção — ver ExpurgaRegistrosDeAcesso.'
            );
        });
    }
}

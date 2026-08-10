<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AccessLog;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auditoria de acesso — Marco Civil da Internet (Lei 12.965/2014).
 *
 * Grava quem, de onde, com qual navegador, o que acessou e quando.
 *
 * O que NÃO é gravado, e por quê: o corpo da requisição. O POST de login
 * carrega a senha em claro, e um log de auditoria que guarda senhas troca um
 * problema de conformidade por um vazamento. Método, rota e resultado bastam
 * para reconstituir a linha do tempo de um acesso.
 */
class RegistraAcesso
{
    /**
     * Rotas que NÃO viram registro, mesmo mudando estado pelo verbo HTTP.
     *
     * `quotes/simulate` é POST porque recebe um corpo grande, mas não persiste
     * nada — é cálculo puro, disparado em debounce a cada tecla digitada na
     * calculadora. Auditá-lo encheria a tabela com dezenas de linhas por
     * orçamento e afogaria os eventos que uma investigação procura.
     */
    private const IGNORADAS = [
        'api/quotes/simulate',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * O usuário é capturado ANTES de seguir o pipeline.
         *
         * A rota de exclusão de conta apaga o próprio autor: depois de $next
         * ele não existe mais, e `$request->user()` volta um model órfão cujo
         * id violaria a chave estrangeira na hora de gravar.
         */
        $autor = $request->user();

        $response = $next($request);

        if (! $this->deveRegistrar($request)) {
            return $response;
        }

        /*
         * No login não há autor ANTES: a autenticação acontece dentro do
         * controller, que emite um token sem popular $request->user(). Sem esta
         * segunda tentativa, o evento mais importante da auditoria — quem
         * entrou no sistema — ficaria gravado sem dono.
         */
        $autor ??= $this->autorDoLogin($request);

        $this->registrar($request, $response, $autor?->id, $autor?->tenant_id);

        return $response;
    }

    /**
     * O titular da tentativa de login, pelo e-mail enviado.
     *
     * Vale para a tentativa FRUSTRADA também, e de propósito: é o vínculo que
     * transforma linhas soltas em "cinco tentativas contra esta conta em dois
     * minutos", que é a pergunta que uma investigação faz. E-mail inexistente
     * devolve null — não há a quem vincular, e o registro fica assim mesmo.
     */
    private function autorDoLogin(Request $request): ?User
    {
        if (! $request->is('api/login')) {
            return null;
        }

        $email = $request->input('email');

        if (! is_string($email) || $email === '') {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    /**
     * Registra escrita e evento de autenticação; ignora leitura.
     *
     * GET fica de fora por decisão de proporcionalidade: a lei pede registro de
     * ACESSO à aplicação, e o acesso já fica provado pelo login. Auditar toda
     * leitura de uma SPA — que busca materiais, parâmetros e histórico a cada
     * navegação — multiplicaria a tabela por dez sem acrescentar informação.
     */
    private function deveRegistrar(Request $request): bool
    {
        if (in_array($request->path(), self::IGNORADAS, true)) {
            return false;
        }

        if ($request->is('api/login')) {
            return true;
        }

        return in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function registrar(Request $request, Response $response, ?int $userId, ?int $tenantId): void
    {
        $status = $response->getStatusCode();

        /*
         * O autor pode ter deixado de existir durante a requisição (exclusão de
         * conta). O vínculo cai para null e o registro sobrevive identificado
         * pelo subject_hash — que é exatamente o desenho pedido pelo encontro
         * entre a guarda do Marco Civil e o esquecimento da LGPD.
         */
        $vinculoValido = $userId !== null && User::query()->whereKey($userId)->exists();

        AccessLog::create([
            'user_id' => $vinculoValido ? $userId : null,
            'tenant_id' => $vinculoValido ? $tenantId : null,
            'subject_hash' => AccessLog::hashDoSujeito($userId),
            'ip_address' => (string) $request->ip(),
            'user_agent' => $this->userAgent($request),
            'method' => $request->method(),
            'path' => mb_substr($request->path(), 0, 512),
            'route_name' => $request->route()?->getName(),
            'status_code' => $status,
            'event' => $this->evento($request, $status),
            'occurred_at' => now(),
        ]);
    }

    /**
     * Rótulo do evento.
     *
     * Método e rota não distinguem o que importa: POST /login com 200 é o
     * acesso legítimo, com 422 é a tentativa frustrada — e é a sequência de
     * tentativas frustradas que denuncia um ataque. O rótulo separa os dois
     * num campo indexado, sem obrigar quem consulta a conhecer os códigos HTTP
     * de cada rota.
     */
    private function evento(Request $request, int $status): string
    {
        $sucesso = $status >= 200 && $status < 300;

        return match (true) {
            $request->is('api/login') => $sucesso ? 'login' : 'login.falha',
            $request->is('api/logout') => 'logout',
            $request->is('api/account') && $request->isMethod('DELETE') => 'conta.exclusao',
            default => $sucesso ? 'escrita' : 'escrita.falha',
        };
    }

    /** Truncado: User-Agent é cabeçalho livre e um cliente pode mandar quilobytes. */
    private function userAgent(Request $request): ?string
    {
        $agent = $request->userAgent();

        return $agent === null ? null : mb_substr($agent, 0, 1000);
    }
}

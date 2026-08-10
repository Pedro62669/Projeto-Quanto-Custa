<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Direito ao esquecimento — LGPD (Lei 13.709/2018, art. 18, VI).
 *
 * Apaga a empresa e tudo que pende dela, em definitivo. Duas fronteiras
 * governam o desenho:
 *
 *  1. O banco é transacional; o disco não é. Por isso o arquivo do logotipo só
 *     é removido DEPOIS do commit. Apagá-lo dentro da transação perderia a
 *     imagem para sempre se o rollback acontecesse depois — o arquivo não volta
 *     com o ROLLBACK, e o registro que aponta para ele voltaria.
 *
 *  2. Os registros de acesso NÃO são apagados. A guarda de seis meses do Marco
 *     Civil é obrigação legal, e a própria LGPD (art. 16, I) a reconhece como
 *     exceção à eliminação. Eles sobrevivem anonimizados: as chaves para
 *     usuário e empresa caem para null pelo nullOnDelete, e o que resta é o
 *     pseudônimo assinado. Nenhum dado pessoal permanece; a prova de acesso,
 *     sim.
 */
class ExclusaoDeConta
{
    /**
     * Apaga a empresa inteira e devolve o que foi removido.
     *
     * @return array<string, int|string|null> Inventário para o comprovante de
     *                                        exclusão que a LGPD manda dar ao titular.
     */
    public function executar(Tenant $tenant): array
    {
        $logo = $tenant->logo_path;

        $inventario = DB::transaction(function () use ($tenant): array {
            $usuarios = User::query()->where('tenant_id', $tenant->id)->pluck('id');

            /*
             * Tokens do Sanctum saem à mão porque a tabela é polimórfica
             * (tokenable_id/type) e, sem chave estrangeira, nenhuma cascata os
             * alcança. Deixá-los para trás manteria credenciais órfãs de uma
             * conta que o titular pediu para apagar — o pior tipo de sobra.
             */
            $tokens = DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $usuarios)
                ->delete();

            $contagem = [
                'empresa' => $tenant->name,
                'usuarios' => $usuarios->count(),
                'tokens_revogados' => $tokens,
                'materiais' => $this->contar(Material::class, $tenant->id),
                'configuracoes_de_custo' => $this->contar(CostSetting::class, $tenant->id),
                'orcamentos' => Quote::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->withTrashed()
                    ->where('tenant_id', $tenant->id)
                    ->count(),
            ];

            /*
             * O delete do Tenant dispara a purga ordenada do próprio model
             * (orçamentos antes de materiais, por causa do restrictOnDelete de
             * quotes.material_id) e a cascata do banco leva os usuários. Ver
             * Tenant::booted().
             */
            $tenant->delete();

            return $contagem;
        });

        // Fora da transação, e só depois do commit: se o banco tivesse voltado
        // atrás, o registro apontaria para um arquivo que já não existiria.
        $inventario['logotipo_removido'] = $this->removerLogotipo($logo);

        return $inventario;
    }

    /** @param class-string<Model> $model */
    private function contar(string $model, int $tenantId): int
    {
        return $model::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->count();
    }

    /**
     * Remove o arquivo físico do logotipo.
     *
     * Falha em apagar o arquivo não desfaz a exclusão dos dados: o titular pediu
     * para sair, e devolvê-lo ao sistema porque um arquivo resistiu seria a
     * troca errada. O retorno informa o que aconteceu para que o comprovante
     * diga a verdade, e a sobra vira trabalho de limpeza — não de conformidade.
     */
    private function removerLogotipo(?string $caminho): bool
    {
        if ($caminho === null || $caminho === '') {
            return false;
        }

        $disco = Storage::disk((string) config('filesystems.default'));

        if (! $disco->exists($caminho)) {
            return false;
        }

        return $disco->delete($caminho);
    }
}

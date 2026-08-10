<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Enums\PlanStatus;
use App\Enums\PlanType;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Billing\QuotaGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestão das empresas assinantes.
 *
 * O que este controller deliberadamente NÃO faz: definir a senha de um usuário
 * de empresa. Poder trocar a senha de alguém é poder entrar como essa pessoa —
 * e um sistema em que o operador da plataforma consegue se passar por um cliente
 * não tem como responder "quem aprovou este orçamento?" com honestidade. Suporte
 * legítimo se faz derrubando as sessões e mandando o link de redefinição, que é
 * o que existe aqui: o titular escolhe a senha nova, ninguém mais a conhece.
 */
class PlatformTenantController extends Controller
{
    /** Lista das empresas, com o consumo de cada uma. */
    public function index(Request $request, QuotaGuard $quotas): JsonResponse
    {
        $empresas = Tenant::query()
            ->withCount('users')
            ->when($request->filled('search'), fn ($q) => $q->whereLike(
                'name', "%{$request->string('search')}%", caseSensitive: false,
            ))
            ->when($request->filled('plan_type'), fn ($q) => $q->where('plan_type', $request->string('plan_type')))
            ->when($request->filled('plan_status'), fn ($q) => $q->where('plan_status', $request->string('plan_status')))
            ->when($request->filled('state'), fn ($q) => $q->where('state', $request->string('state')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25))
            ->through(fn (Tenant $tenant) => $this->resumo($tenant, $quotas));

        return response()->json($empresas);
    }

    public function show(Tenant $tenant, QuotaGuard $quotas): JsonResponse
    {
        $tenant->load(['users:id,tenant_id,name,email,role,is_active,last_login_at']);

        return response()->json([
            'data' => $this->resumo($tenant, $quotas) + [
                'usuarios' => $tenant->users,
                'assinaturas' => $tenant->subscriptions()->orderByDesc('started_at')->get(),
            ],
        ]);
    }

    /**
     * Troca de plano e cortesias — o "promover para PRO" do painel.
     *
     * Muda o plano SEM passar pelo gateway de propósito: é a rota da cortesia,
     * do parceiro, do teste estendido. Cobrança de verdade entra por webhook.
     * Um caminho manual que também mexesse no gateway abriria a porta para o
     * painel e o provedor discordarem sobre quanto o cliente deve.
     */
    public function updatePlan(Request $request, Tenant $tenant): JsonResponse
    {
        $dados = $request->validate([
            'plan_type' => ['required', Rule::enum(PlanType::class)],
            'plan_status' => ['sometimes', Rule::enum(PlanStatus::class)],

            /*
             * Cortesias. `nullable` é significativo: mandar null explicitamente
             * REMOVE a exceção e devolve a empresa ao padrão do plano — é como
             * se desfaz uma cortesia concedida por engano.
             */
            'max_materials' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_quotes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_clients' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'subscription_ends_at' => ['sometimes', 'nullable', 'date'],
            'motivo' => ['required', 'string', 'max:255'],
        ], [
            'motivo.required' => 'Descreva o motivo da alteração manual de plano.',
        ]);

        /*
         * forceFill: os campos de plano estão fora do $fillable do Tenant, para
         * que nenhuma tela de perfil da empresa consiga virar um upgrade grátis.
         * A escrita legítima é esta, explícita e restrita ao admin de plataforma.
         */
        $tenant->forceFill(collect($dados)->except('motivo')->all())->save();

        /*
         * O `motivo` não vira coluna: ele já é auditado. O RegistraAcesso grava
         * método, rota, autor e resultado de todo PATCH — e a exigência do campo
         * aqui é o que obriga quem promove a escrever por quê, mesmo que o
         * destino do texto seja o log de acesso e não uma tabela nova.
         */
        return response()->json([
            'data' => $tenant->fresh(),
            'message' => "Plano atualizado para {$tenant->plan_type->label()}.",
        ]);
    }

    /**
     * Suspende ou reativa a empresa.
     *
     * Mexe em `is_active`, nunca em `plan_status`. São eixos diferentes: um é
     * decisão administrativa, o outro é consequência de pagamento. Se fossem o
     * mesmo campo, o webhook de fatura paga reabriria a conta de quem foi
     * suspenso por abuso.
     */
    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate([
            'ativo' => ['required', 'boolean'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        $tenant->forceFill(['is_active' => $request->boolean('ativo')])->save();

        return response()->json([
            'data' => $tenant->fresh(),
            'message' => $tenant->is_active
                ? 'Empresa reativada.'
                : 'Empresa suspensa. Os dados foram preservados.',
        ]);
    }

    /** @return array<string, mixed> */
    private function resumo(Tenant $tenant, QuotaGuard $quotas): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'legal_name' => $tenant->legal_name,
            'document' => $tenant->document,
            'email' => $tenant->email,
            'city' => $tenant->city,
            'state' => $tenant->state,
            'is_active' => $tenant->is_active,
            'acesso_liberado' => $tenant->acessoLiberado(),
            'plan_type' => $tenant->plan_type->value,
            'plan_label' => $tenant->plan_type->label(),
            'plan_status' => $tenant->plan_status->value,
            'plan_status_label' => $tenant->plan_status->label(),
            'subscription_ends_at' => $tenant->subscription_ends_at?->toIso8601String(),
            'users_count' => $tenant->users_count ?? $tenant->users()->count(),
            'created_at' => $tenant->created_at?->toIso8601String(),
            'cotas' => $quotas->resumo($tenant),
        ];
    }
}

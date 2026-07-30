<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestão de usuários (somente admin).
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'is_active', 'created_at'])
            ->withCount('quotes')
            ->when($request->filled('search'), fn ($q) => $q
                ->where(fn ($sub) => $sub
                    ->whereLike('name', "%{$request->string('search')}%", caseSensitive: false)
                    ->orWhereLike('email', "%{$request->string('search')}%", caseSensitive: false)))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['boolean'],
        ]);

        // O cast 'hashed' do model cuida do hash — não fazer Hash::make aqui,
        // sob pena de dupla aplicação.
        $user = User::create($validated);

        return response()->json(['data' => $user], JsonResponse::HTTP_CREATED);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $user->loadCount('quotes')]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => ['sometimes', 'string', 'min:8'],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Trava contra o cenário em que o último admin se rebaixa ou se desativa
        // e ninguém mais consegue administrar o sistema.
        $this->guardAgainstLastAdminLockout($user, $validated);

        $user->update($validated);

        return response()->json(['data' => $user->fresh()]);
    }

    /**
     * Desativa em vez de apagar: os orçamentos do usuário permanecem no histórico.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => 'Você não pode desativar a própria conta.',
            ]);
        }

        $this->guardAgainstLastAdminLockout($user, ['is_active' => false]);

        $user->update(['is_active' => false]);

        return response()->json(['message' => 'Usuário desativado.']);
    }

    /** @param  array<string, mixed>  $changes */
    private function guardAgainstLastAdminLockout(User $user, array $changes): void
    {
        $losesAdmin = (isset($changes['role']) && $changes['role'] !== UserRole::Admin->value)
            || (array_key_exists('is_active', $changes) && ! $changes['is_active']);

        if (! $user->isAdmin() || ! $losesAdmin) {
            return;
        }

        $remainingAdmins = User::where('role', UserRole::Admin)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->count();

        if ($remainingAdmins === 0) {
            throw ValidationException::withMessages([
                'role' => 'Este é o último administrador ativo. Promova outro usuário antes de alterá-lo.',
            ]);
        }
    }
}

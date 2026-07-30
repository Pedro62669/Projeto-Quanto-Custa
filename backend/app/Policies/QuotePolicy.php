<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;

/**
 * Um orçamento pertence a quem o criou. Admin enxerga tudo.
 *
 * Ativada por authorizeResource() no QuoteController: impede IDOR
 * (trocar o id na URL para ler o orçamento de outro usuário).
 */
class QuotePolicy
{
    /**
     * Executado antes de qualquer outro método: admin passa direto.
     * Retornar null delega a decisão ao método específico.
     */
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Quote $quote): bool
    {
        return $user->id === $quote->user_id;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Quote $quote): bool
    {
        return $user->id === $quote->user_id;
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $user->id === $quote->user_id;
    }
}

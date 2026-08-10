<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmação do cancelamento da assinatura.
 *
 * Pede a senha pelo mesmo motivo que a exclusão de conta: é um endpoint que
 * movimenta dinheiro de verdade — dentro dos sete dias do CDC ele dispara um
 * estorno no gateway — e não pode ser alcançável só com um token de sessão
 * roubado. A senha é o fator que o atacante de sessão não tem.
 */
class CancelarAssinaturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        /*
         * Só o admin da própria empresa cancela. Usuário comum não desliga o
         * sistema de todo mundo; admin de plataforma não tem assinatura própria
         * para cancelar.
         */
        return $user !== null
            && $user->isAdmin()
            && $user->tenant_id !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.current_password' => 'Senha incorreta.',
        ];
    }
}

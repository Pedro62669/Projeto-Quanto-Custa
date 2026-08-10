<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmação da exclusão definitiva da conta.
 *
 * Pede a senha atual, e isso merece justificativa porque a Fase 1 fala em
 * "exclusão em um clique": o clique continua sendo um só do ponto de vista de
 * quem usa — a interface pede a senha na mesma janela de confirmação. O que
 * não pode existir é um endpoint irreversível, capaz de destruir a empresa
 * inteira, alcançável só com um token de sessão. Um XSS ou um notebook
 * destravado viraria perda total e sem volta.
 *
 * A senha é o único fator que o atacante de sessão não tem. Se a conformidade
 * exigir remover esta barreira, o lugar é aqui — e a consequência está escrita.
 */
class ExcluirContaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        /*
         * Só o admin DA EMPRESA apaga a empresa.
         *
         * Usuário comum não leva a conta de todo mundo junto. E o admin de
         * plataforma (tenant_id nulo) também não passa: ele não tem empresa
         * para excluir, e deixá-lo cair aqui produziria um erro obscuro em vez
         * de uma recusa clara.
         */
        return $user !== null
            && $user->isAdmin()
            && $user->tenant_id !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `current_password` valida contra o hash do usuário autenticado
            // sem que a senha passe por comparação manual em lugar nenhum.
            'password' => ['required', 'string', 'current_password'],

            /*
             * Confirmação escrita, no espírito do "sim, eu entendi".
             *
             * Não é burocracia: é a diferença entre um clique errado e uma
             * decisão. A ação não tem desfazer, não tem lixeira e não tem
             * backup que o próprio titular consiga acionar.
             */
            'confirmacao' => ['required', 'string', 'in:EXCLUIR'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.current_password' => 'Senha incorreta.',
            'confirmacao.in' => 'Digite EXCLUIR para confirmar a exclusão definitiva.',
        ];
    }
}

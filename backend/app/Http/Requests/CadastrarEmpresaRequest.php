<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Cadastro público de uma empresa nova.
 *
 * O formulário é curto de propósito: nome da empresa, nome de quem responde,
 * e-mail e senha. CNPJ, endereço e redes sociais vêm depois, no perfil — pedir
 * tudo na porta de entrada troca cadastros por desistências, e nenhum desses
 * campos é necessário para o sistema funcionar. Eles só passam a importar quando
 * a pessoa for emitir a primeira proposta em PDF, e aí a própria tela cobra.
 */
class CadastrarEmpresaRequest extends FormRequest
{
    /** Rota pública: não há quem autorizar. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'empresa' => ['required', 'string', 'max:255'],

            'nome' => ['required', 'string', 'max:255'],

            /*
             * Único GLOBALMENTE, e não por empresa.
             *
             * O login é por e-mail e não pede o nome da empresa junto — se dois
             * inquilinos pudessem ter o mesmo endereço, a autenticação não teria
             * como escolher qual dos dois. É a diferença deste campo para o
             * `cpf_cnpj` de clientes, que é único só dentro da empresa.
             */
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],

            /*
             * `confirmed` exige `password_confirmation`: quem erra a senha no
             * cadastro público não tem sessão nem e-mail verificado para pedir
             * recuperação, e fica trancado para fora de uma conta recém-criada.
             */
            'password' => ['required', 'string', 'confirmed', Password::min(8)],

            // Opcional aqui, único quando vier — a coluna tem índice único.
            'documento' => ['nullable', 'string', 'max:14', 'unique:tenants,document'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'empresa.required' => 'Informe o nome da sua cartonagem.',
            'email.unique' => 'Já existe uma conta com este e-mail.',
            'password.confirmed' => 'A confirmação de senha não confere.',
            'documento.unique' => 'Já existe uma empresa cadastrada com este CNPJ.',
        ];
    }
}

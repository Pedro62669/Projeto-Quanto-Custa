<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona controle de acesso à tabela de usuários já criada pelo Laravel.
 *
 * Optamos por uma coluna `role` simples (enum) em vez de um pacote de ACL:
 * o sistema tem apenas dois níveis (admin/user) e a regra de autorização
 * inteira cabe em uma Policy. Se o produto crescer para permissões
 * granulares, migrar para spatie/laravel-permission é um passo isolado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)
                ->default('user')
                ->after('email')
                ->comment('admin | user');

            // Permite desativar um usuário sem apagar o histórico de orçamentos.
            $table->boolean('is_active')->default(true)->after('role');

            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'is_active']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A empresa assinante. É a raiz do isolamento: toda linha operacional do
 * sistema pendura-se aqui, e é este id que o TenantScope injeta em cada query.
 *
 * Quase tudo é nullable de propósito. O tenant nasce no momento do cadastro,
 * quando só existe um nome — exigir CNPJ e endereço completo ali transformaria
 * o primeiro contato num formulário de contrato. Os dados fiscais são
 * preenchidos depois, no perfil, e é a geração do PDF que cobra o que falta.
 *
 * `document` em vez de `cnpj`: parte do público-alvo é MEI e artesão que fatura
 * no CPF. Guardar só dígitos (sem máscara) mantém a busca e o índice único
 * previsíveis — a formatação é problema da camada de apresentação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();

            // --- Identificação ------------------------------------------
            $table->string('name')->comment('Nome fantasia, exibido na interface');
            $table->string('legal_name')->nullable()->comment('Razão social, usada em documento fiscal');

            /**
             * Único, mas nullable: dois tenants não podem compartilhar o mesmo
             * CNPJ/CPF, e ao mesmo tempo vários podem ainda não tê-lo informado.
             * Em MySQL e PostgreSQL o índice único ignora NULLs, então os dois
             * requisitos convivem sem uma constraint parcial.
             */
            $table->string('document', 14)->nullable()->unique()->comment('CNPJ ou CPF, somente dígitos');

            // --- Contato -------------------------------------------------
            $table->string('email')->nullable();
            $table->string('whatsapp', 20)->nullable()->comment('Somente dígitos, com DDI e DDD');
            $table->string('phone', 20)->nullable();

            // --- Redes sociais -------------------------------------------
            // Handle (@loja), não URL: o link se monta na exibição, e guardar a
            // URL inteira convida a colar endereços de post em vez do perfil.
            $table->string('instagram')->nullable();
            $table->string('tiktok')->nullable();
            $table->string('facebook')->nullable();
            $table->string('website')->nullable();

            // --- Endereço -------------------------------------------------
            $table->string('postal_code', 8)->nullable()->comment('CEP, somente dígitos');
            $table->string('street')->nullable();
            $table->string('street_number', 20)->nullable()->comment('String: existe "s/n" e "120-A"');
            $table->string('complement')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable()->comment('UF');

            /**
             * Caminho no disco de storage, não URL: o disco pode mudar de local
             * (público, S3) sem reescrever linha nenhuma. Quem exibe resolve a
             * URL a partir daqui.
             */
            $table->string('logo_path')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};

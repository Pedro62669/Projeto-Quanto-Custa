<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastreio de frequência de acesso e histórico de e-mails de engajamento.
 *
 * `last_login_at` parece redundante com `access_logs`, que já registra todo
 * login desde a Fase 1 — e seria, não fosse o expurgo. O
 * `compliance:expurgar-acessos` apaga registros com mais de 6 meses, por
 * exigência de minimização da LGPD. Quem sumiu há sete meses é exatamente o
 * usuário que a campanha quer alcançar, e ele não teria mais nenhuma linha lá.
 * A coluna sobrevive ao expurgo porque guarda UM instante, não o rastro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('is_active');

            /**
             * Descadastro de comunicação de engajamento.
             *
             * O e-mail de reengajamento é MARKETING, não transacional: não
             * responde a um ato do usuário, ele apenas chega. A LGPD trata isso
             * como tratamento por legítimo interesse, e legítimo interesse exige
             * oposição fácil (art. 18, §2º). Sem opt-out, o módulo inteiro fica
             * irregular — e o projeto já implementou o resto do compliance.
             *
             * Timestamp e não booleano: a data é a prova de quando o titular se
             * opôs, que é o que se apresenta se alguém questionar.
             */
            $table->timestamp('marketing_opt_out_at')->nullable()->after('last_login_at');

            // A varredura do comando diário: quem sumiu e ainda aceita e-mail.
            $table->index(['is_active', 'last_login_at']);
        });

        /**
         * Histórico de disparos.
         *
         * Impede o efeito mais óbvio de um cron: rodar todo dia e mandar o mesmo
         * e-mail todo dia para quem está de férias. Sem histórico, o critério
         * "sumiu há mais de 10 dias" continua verdadeiro no dia 11, 12, 13...
         */
        Schema::create('engagement_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * nullOnDelete: se a empresa for excluída (direito ao esquecimento),
             * o histórico de disparo perde o vínculo mas não trava a exclusão.
             */
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 30)->default('inatividade');
            $table->timestamp('sent_at');

            $table->timestamps();

            // "Este usuário recebeu algo recentemente?" — a pergunta do comando.
            $table->index(['user_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_emails');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'last_login_at']);
            $table->dropColumn(['last_login_at', 'marketing_opt_out_at']);
        });
    }
};

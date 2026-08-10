<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registros de acesso à aplicação — Marco Civil da Internet (Lei 12.965/2014).
 *
 * A lei obriga a guarda dos registros por seis meses, sob sigilo, e em condição
 * de comprovar autenticidade. Duas consequências no schema:
 *
 *  1. Não há `updated_at`. Uma linha nasce e não muda; a ausência da coluna é a
 *     primeira declaração de que a tabela é append-only, antes de qualquer
 *     regra de aplicação.
 *  2. Há `signature`. É um HMAC do conteúdo do evento com a APP_KEY: quem
 *     editar um IP direto no banco não consegue recalcular a assinatura sem a
 *     chave, e a adulteração fica demonstrável. Vale para ALTERAÇÃO — remoção
 *     de linhas exigiria encadeamento, que serializaria toda escrita da API e
 *     custaria caro numa rota chamada a cada requisição.
 *
 * Sobre o conflito com a LGPD: o titular pede exclusão, e esta tabela precisa
 * sobreviver a ela. Por isso `user_id` e `tenant_id` são nullOnDelete, e a
 * identificação assinada mora em `subject_hash` — um pseudônimo que continua
 * provando "foi sempre o mesmo agente" depois que o vínculo pessoal some. A
 * guarda obrigatória do Marco Civil é exatamente a hipótese de obrigação legal
 * que a LGPD reconhece como exceção ao direito ao esquecimento (art. 16, I).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();

            // Vínculos de conveniência para relatório e join. Perdem-se na
            // exclusão da conta; o registro em si permanece.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();

            /**
             * Pseudônimo estável do usuário: HMAC do id com a APP_KEY.
             *
             * Sobrevive à exclusão da conta e mantém o registro útil (é possível
             * correlacionar ações do mesmo agente), sem guardar dado pessoal.
             * Sem a chave, não se volta do hash para o id.
             */
            $table->char('subject_hash', 64)->nullable();

            // 45 caracteres: comprimento de um IPv6 no formato mais longo,
            // incluindo a notação com IPv4 embutido.
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();

            $table->string('method', 10);
            $table->string('path', 512);
            $table->string('route_name')->nullable();
            $table->unsignedSmallInteger('status_code');

            /**
             * Rótulo do evento (`login`, `login.falha`, `conta.exclusao`, ...).
             *
             * Existe além de método+rota porque a mesma rota produz eventos de
             * significado diferente: POST /login com 200 e com 422 são o acesso
             * legítimo e a tentativa frustrada, e é a tentativa frustrada que
             * uma investigação procura.
             */
            $table->string('event', 40);

            $table->timestamp('occurred_at');

            /** HMAC-SHA256 do conteúdo assinável — ver AccessLog::assinatura(). */
            $table->char('signature', 64);

            // Sem timestamps(): a tabela não tem updated_at de propósito.

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);

            // Varredura da retenção: o expurgo dos seis meses filtra só por data.
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O dinheiro que entra PARA A PLATAFORMA.
 *
 * Tabelas próprias, e não `transactions` da Fase 4 — a distinção é a mais
 * importante deste arquivo. `transactions` é o livro caixa DO ASSINANTE: as
 * caixas que ele vende para os clientes dele. Registrar a mensalidade lá
 * significaria lançar a despesa do assinante como receita dele, inflar o
 * faturamento do painel financeiro que ele usa para decidir preço, e — pior —
 * fazer a regra dos 7 dias do CDC contar o prazo a partir da venda de uma caixa
 * qualquer, porque é essa a data que estaria em `created_at`.
 *
 * São dois caixas diferentes, de duas empresas diferentes. Ficam separados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('plan_type', 10);
            $table->string('status', 12);

            $table->string('gateway', 20)->comment('fake | stripe | pagarme');
            $table->string('gateway_subscription_id')->nullable();

            $table->decimal('amount', 10, 2)->comment('Mensalidade contratada');

            /**
             * A âncora dos 7 dias do CDC (art. 49).
             *
             * Data da CONTRATAÇÃO, gravada uma vez e nunca mais tocada. Não use
             * `created_at` para isso: uma reimportação, um seed ou um backfill
             * mexem em created_at sem querer, e aqui o campo decide se o cliente
             * tem direito a dinheiro de volta.
             */
            $table->timestamp('started_at');

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();

            $table->timestamps();

            /*
             * Uma assinatura do gateway não pode virar duas linhas aqui — é a
             * segunda linha de defesa contra o webhook reenviado. Composto com o
             * gateway porque ids de provedores diferentes podem colidir.
             */
            $table->unique(['gateway', 'gateway_subscription_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            $table->string('gateway', 20);
            $table->string('gateway_payment_id')->nullable();

            $table->decimal('amount', 10, 2);
            $table->string('status', 10)->comment('pending | paid | failed | refunded');

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->decimal('refunded_amount', 10, 2)->nullable()
                ->comment('Reembolso pode ser parcial; null enquanto não houver');

            $table->timestamps();

            $table->unique(['gateway', 'gateway_payment_id']);

            // A varredura do faturamento do painel de plataforma.
            $table->index(['status', 'paid_at']);
            $table->index(['tenant_id', 'status']);
        });

        /**
         * Idempotência de webhook.
         *
         * Todo gateway sério REENVIA o mesmo evento quando não recebe 2xx a
         * tempo — e "a tempo" inclui o dia em que o banco ficou lento. Sem esta
         * tabela, um reenvio de `invoice.paid` estenderia o período duas vezes,
         * e um de `charge.refunded` tentaria reembolsar dinheiro já devolvido.
         *
         * O unique é a garantia real: mesmo com dois webhooks concorrentes, o
         * segundo INSERT falha no banco em vez de depender de um SELECT prévio
         * que perde a corrida.
         */
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20);
            $table->string('external_id')->comment('id do evento no gateway');
            $table->string('type', 60);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable()
                ->comment('Null = recebido mas ainda não aplicado');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
    }
};

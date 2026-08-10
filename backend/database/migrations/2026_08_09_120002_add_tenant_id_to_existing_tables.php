<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pendura as tabelas operacionais no tenant e adota os dados que já existem.
 *
 * A ordem aqui não é estética. A coluna entra NULLABLE, os dados antigos são
 * adotados por um tenant de migração, e só então ela vira NOT NULL: numa base
 * que já tem orçamentos gravados, criar a coluna direto como obrigatória falha
 * na primeira linha. Bases vazias passam pelo mesmo caminho sem efeito.
 *
 * Por que NOT NULL importa num sistema multi-inquilino: `tenant_id` nulo numa
 * tabela operacional é uma linha que nenhum TenantScope alcança — invisível
 * para o dono e para o suporte, e visível para quem consultar sem escopo. O
 * banco recusando o nulo é a última linha de defesa se a trait falhar.
 */
return new class extends Migration
{
    /** Tabelas que pertencem a UMA empresa e morrem junto com ela. */
    private const OPERACIONAIS = ['materials', 'cost_settings', 'quotes'];

    public function up(): void
    {
        // ── 1. Coluna nullable em todas as tabelas ───────────────────────
        //
        // users vem junto mas segue outra regra: nullable PERMANENTE, porque o
        // admin de plataforma (quem opera o SaaS) não pertence a empresa
        // nenhuma. É esse nulo que o TenantScope lê como "enxerga tudo".
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained()
                /*
                 * Cascade, e não nullOnDelete: com nulo significando "admin de
                 * plataforma", anular o vínculo ao excluir a empresa promoveria
                 * todos os funcionários dela a superusuário do sistema. Excluir
                 * o tenant tem que levar os usuários dele junto.
                 */
                ->cascadeOnDelete();
        });

        foreach (self::OPERACIONAIS as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->foreignId('tenant_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        // ── 2. Adoção dos dados existentes ───────────────────────────────
        $tenantId = $this->tenantDeMigracao();

        if ($tenantId !== null) {
            DB::table('users')->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);

            foreach (self::OPERACIONAIS as $tabela) {
                DB::table($tabela)->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
            }
        }

        // ── 3. Só agora a coluna operacional pode ser obrigatória ────────
        foreach (self::OPERACIONAIS as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable(false)->change();
            });
        }

        // ── 4. Índices ───────────────────────────────────────────────────
        //
        // TODA query escopada carrega `where tenant_id = ?`, então esta coluna
        // entra na frente dos índices que já existiam. Sem isso, um tenant
        // pequeno pagaria varredura proporcional ao tamanho da base inteira.
        Schema::table('materials', function (Blueprint $table) {
            $table->index(['tenant_id', 'is_active', 'type']);
        });

        Schema::table('cost_settings', function (Blueprint $table) {
            $table->index(['tenant_id', 'effective_from']);
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->index(['tenant_id', 'user_id']);

            /*
             * A referência passa a ser única DENTRO da empresa, não na base.
             *
             * O índice global era coerente com um contador global. Com cada
             * empresa numerando a partir do 1, a segunda a emitir uma proposta
             * no ano colidiria em ORC-2026-000001 — o índice antigo transforma
             * a numeração por inquilino em erro de integridade na primeira
             * venda do segundo cliente.
             */
            $table->dropUnique('quotes_reference_unique');
            $table->unique(['tenant_id', 'reference']);
        });

        $this->recriarContadores();
    }

    /**
     * Cria o tenant que adota os dados pré-multi-inquilino — e só se houver o
     * que adotar. Numa base zerada (CI, instalação nova) nada é criado, para
     * não plantar uma empresa fantasma em toda instalação.
     */
    private function tenantDeMigracao(): ?int
    {
        $temDados = DB::table('users')->exists()
            || collect(self::OPERACIONAIS)->contains(fn (string $t) => DB::table($t)->exists());

        if (! $temDados) {
            return null;
        }

        return (int) DB::table('tenants')->insertGetId([
            'name' => 'Empresa principal',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Refaz `quote_counters` com chave composta (tenant_id, year).
     *
     * A sequência era global por ano. Mantê-la assim vazaria volume de negócio
     * entre inquilinos: a empresa que emitisse o ORC-2026-000500 saberia que
     * outras 499 propostas foram feitas na plataforma, e sua própria numeração
     * chegaria cheia de buracos. Cada empresa passa a contar do zero.
     *
     * Recriar em vez de ALTER: trocar chave primária é a operação menos
     * portátil que existe entre SQLite, MySQL e PostgreSQL. Criar-copiar-
     * renomear se comporta igual nos três, e as linhas antigas são levadas
     * junto para o tenant de migração — perder o último número emitido
     * reiniciaria a contagem e colidiria com referências já gravadas.
     */
    private function recriarContadores(): void
    {
        $tenantId = DB::table('tenants')->orderBy('id')->value('id');

        Schema::create('quote_counters_new', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);

            $table->primary(['tenant_id', 'year']);
        });

        if ($tenantId !== null) {
            $antigos = DB::table('quote_counters')->get();

            foreach ($antigos as $linha) {
                DB::table('quote_counters_new')->insert([
                    'tenant_id' => $tenantId,
                    'year' => $linha->year,
                    'last_number' => $linha->last_number,
                ]);
            }
        }

        Schema::drop('quote_counters');
        Schema::rename('quote_counters_new', 'quote_counters');
    }

    public function down(): void
    {
        Schema::create('quote_counters_old', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });

        /*
         * Volta a UMA linha por ano. Com vários inquilinos os contadores
         * colidem, e o maior é o único valor seguro: qualquer número abaixo
         * dele reemitiria uma referência já usada.
         */
        $consolidados = DB::table('quote_counters')
            ->select('year', DB::raw('MAX(last_number) as last_number'))
            ->groupBy('year')
            ->get();

        foreach ($consolidados as $linha) {
            DB::table('quote_counters_old')->insert([
                'year' => $linha->year,
                'last_number' => $linha->last_number,
            ]);
        }

        Schema::drop('quote_counters');
        Schema::rename('quote_counters_old', 'quote_counters');

        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_active', 'type']);
        });

        Schema::table('cost_settings', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'effective_from']);
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'user_id']);

            /*
             * Volta ao único global. Falha de propósito se duas empresas já
             * emitiram a mesma referência — e é o que se espera: reverter aqui
             * significaria escolher qual das duas propostas perde o número, e
             * essa não é decisão de migration.
             */
            $table->dropUnique(['tenant_id', 'reference']);
            $table->unique('reference');
        });

        foreach ([...self::OPERACIONAIS, 'users'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador de referências de orçamento, por ano.
 *
 * Por que uma tabela em vez de COUNT(*)+1 sobre `quotes`: a contagem sofre
 * corrida — dois pedidos simultâneos leem o mesmo total e geram a mesma
 * referência, violando o índice único.
 *
 * Por que não uma SEQUENCE do PostgreSQL: sequences não existem no MySQL nem
 * no SQLite, e amarrariam o schema a um único driver. Uma linha travada com
 * lockForUpdate() dá a mesma garantia de unicidade em qualquer banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_counters');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peças do modelo livre — a caixa que não cabe em equação nenhuma.
 *
 * Cada linha é um retângulo que alguém vai cortar: nome, material, medida e
 * quantas saem por caixa. O motor soma as áreas em vez de derivá-las do
 * BlankCalculator.
 *
 * Três decisões que valem o comentário:
 *
 * 1. `tenant_id` DENORMALIZADO, mesmo a empresa já sendo derivável pelo
 *    orçamento. É a lição que `installments` documenta desde a Fase 4: o
 *    TenantScope filtra a tabela que está sendo CONSULTADA, e a ficha técnica
 *    consulta as peças diretamente ("o que preciso cortar"). Sem a coluna, essa
 *    query atravessaria empresas — o IDOR que a Fase 1 fechou.
 *
 * 2. `component_role` é STRING com cast do enum ComponentRole, não enum de
 *    banco e não um `part_type` próprio. O papel do componente já existe no
 *    sistema desde a lista de materiais da Fase 3, e o TIPO do insumo
 *    (papelão, papel, tecido) já mora no material apontado — repeti-lo aqui
 *    criaria duas verdades que divergem no primeiro cadastro corrigido.
 *
 * 3. `quantity` é POR CAIXA, não pelo lote. "2 laterais" num pedido de 100
 *    são 200 peças, e o motor multiplica. É como o resto do sistema já pensa
 *    (area_m2_per_unit × quantidade) e é como quem corta descreve a peça —
 *    ninguém diz "preciso de 200 laterais", diz "duas de cada lado".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_custom_parts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();

            /*
             * restrictOnDelete como em `quotes.material_id`, e pela mesma razão:
             * ninguém apaga uma matéria-prima já usada em proposta emitida. Na
             * prática o restrict quase nunca dispara — o MaterialController
             * DESATIVA em vez de apagar —, e é justamente por isso que ele pode
             * ficar: é uma rede para o caminho que não passa pelo controller.
             */
            $table->foreignId('material_id')->constrained()->restrictOnDelete();

            // "Lateral longa", "Fundo", "Capa", "Tampa interna".
            $table->string('name', 120);

            $table->string('component_role', 12)
                ->default('structure')
                ->comment('structure | wrap — ver App\Enums\ComponentRole');

            /*
             * Milímetros inteiros, como em `quotes`. Ninguém corta 300,5mm numa
             * guilhotina de cartonagem, e a fração daria uma falsa precisão que
             * o chão de fábrica não consegue executar.
             */
            $table->unsignedInteger('width_mm');
            $table->unsignedInteger('length_mm');

            $table->unsignedSmallInteger('quantity')->default(1)
                ->comment('Peças iguais POR CAIXA, não pelo lote');

            $table->timestamps();

            // A varredura da ficha técnica e do motor: as peças de um orçamento,
            // escopadas por empresa.
            $table->index(['tenant_id', 'quote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_custom_parts');
    }
};

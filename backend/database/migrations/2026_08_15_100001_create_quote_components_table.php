<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lista de materiais do orçamento — o que era custo e agora também é dado.
 *
 * Até aqui o orçamento gravava `hardware_cost` e nada sobre QUAIS ferragens.
 * Uma caixa com quatro ímãs de neodímio e uma com quatro rebites produziam a
 * mesma linha no banco, e as duas consequências pareciam problemas separados:
 *
 * 1. A ficha técnica saía sem os ímãs. A produção recebia a lista de separação
 *    do papelão e ia comprar ferragem de memória.
 * 2. Nenhum orçamento salvo podia ser reaberto ou duplicado, porque metade da
 *    especificação não estava em lugar nenhum.
 *
 * O revestimento tinha o mesmo defeito até `wrap_material_id`. Esta tabela
 * fecha o resto.
 *
 * ## Por que o revestimento NÃO vem para cá
 *
 * A assimetria é de cardinalidade, e é deliberada. O revestimento é singular —
 * uma pele por peça, garantido em `QuoteController::resolveComponents()` — e é
 * o único componente que entra no plano de corte, onde a busca pela medida da
 * folha precisa ser direta. Ferragem é lista, e berço é um só mas com
 * parâmetros de construção que moram em `quotes`.
 *
 * Trazer o revestimento para cá obrigaria todo consumidor a reestabelecer
 * "existe no máximo um" — em consulta, em recurso e em tela. O ganho seria
 * simetria de escrita; o custo, uma invariante espalhada.
 *
 * ## Estrutura também fica de fora
 *
 * Ela já é `quotes.material_id` desde o primeiro orçamento. `resolveComponents`
 * ignora a linha `structure` quando o frontend a envia (ele envia a lista
 * completa, que é como a exibe), e este armazenamento segue a mesma regra: uma
 * segunda cópia do papelão cinza seria a segunda verdade a divergir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_components', function (Blueprint $table) {
            $table->id();

            /*
             * `tenant_id` DENORMALIZADO, mesmo a empresa sendo derivável pelo
             * orçamento. Mesma lição que `quote_custom_parts` e `installments`
             * já documentam: o TenantScope filtra a tabela CONSULTADA, e a
             * ficha técnica consulta os componentes diretamente para montar a
             * lista de separação. Sem a coluna, essa consulta atravessaria
             * empresas.
             */
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();

            /*
             * restrictOnDelete como em `quotes.material_id` e nas peças do
             * modelo livre: ninguém apaga matéria-prima já usada em proposta
             * emitida. Na prática quase nunca dispara, porque o controller
             * DESATIVA em vez de apagar — e é por isso que pode ficar, como
             * rede para o caminho que não passa pelo controller.
             */
            $table->foreignId('material_id')->constrained()->restrictOnDelete();

            $table->string('component_role', 12)
                ->comment('hardware | cradle — ver App\Enums\ComponentRole');

            /*
             * Nullable de propósito, e não default 1.
             *
             * Só ferragem se conta: "quatro ímãs" é uma frase que existe.
             * "Quantos berços" não é — o berço é um, com uma grade descrita
             * pelas colunas `cradle_*` do orçamento. Um `1` gravado ali seria
             * um número inventado que a próxima pessoa tentaria interpretar.
             *
             * Decimal e não inteiro porque a validação aceita fracionário
             * (`components.*.quantity` é `numeric`): fita de cetim é vendida
             * por peça e consumida em metro e meio.
             */
            $table->decimal('quantity', 10, 3)->nullable()
                ->comment('Peças POR CAIXA. Null em berço, que não se conta.');

            $table->timestamps();

            /*
             * O índice que a ficha técnica usa: "componentes deste orçamento,
             * agrupados por papel". Sem ele, montar a lista de separação de um
             * orçamento varreria a tabela inteira da empresa.
             */
            $table->index(['quote_id', 'component_role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_components');
    }
};

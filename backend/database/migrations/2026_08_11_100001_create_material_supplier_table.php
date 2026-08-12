<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem fornece o quê.
 *
 * Muitos-para-muitos, e não uma coluna de texto no fornecedor, porque a
 * pergunta que a empresa faz é a INVERSA da que a tela mostra: "acabou o
 * papelão E, quem me vende?". Texto livre responde só na direção em que foi
 * escrito, e ainda separa "Papelão E" de "papelao e 1,5mm" como se fossem coisas
 * diferentes. Com a relação, os dois lados são consulta.
 *
 * Sem `tenant_id` nesta tabela, e é uma decisão, não esquecimento: as duas
 * pontas já carregam a sua, e uma linha só nasce por
 * SupplierController::validated(), que valida os ids contra os materiais JÁ
 * filtrados pelo TenantScope. Repetir a coluna aqui criaria um terceiro lugar
 * onde o vínculo pode discordar de si mesmo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_supplier', function (Blueprint $table) {
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();

            /*
             * Chave primária composta, sem `id` e sem timestamps.
             *
             * É a tabela que impede o mesmo material ser ligado duas vezes ao
             * mesmo fornecedor — o que apareceria na tela como a etiqueta
             * repetida. `sync()` já evita, mas a garantia não pode depender de
             * o chamador lembrar de usar o método certo.
             *
             * A ordem importa: (supplier_id, material_id) serve de índice para
             * "os materiais deste fornecedor", que é a consulta da listagem.
             */
            $table->primary(['supplier_id', 'material_id']);

            // A consulta inversa — "os fornecedores deste material" — precisa do
            // seu próprio índice: o composto acima não a atende.
            $table->index('material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_supplier');
    }
};

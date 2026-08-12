<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fornecedores e o que cada um vende.
 *
 * O vínculo com material é muitos-para-muitos e chega por uma lista de ids no
 * corpo da requisição — que é exatamente o formato que um cliente hostil sabe
 * forjar. Daí os dois testes de fronteira aqui: id de outra empresa não entra, e
 * o que a API aceita ela devolve.
 */
class FornecedorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->create();

        $this->usuario = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::Admin,
        ]);
    }

    #[Test]
    public function o_fornecedor_grava_os_materiais_que_fornece(): void
    {
        $papelao = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'name' => 'Papelão cinza 1,5mm',
        ]);

        $kraft = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'name' => 'Papel kraft 120g',
        ]);

        $resposta = $this->actingAs($this->usuario)
            ->postJson('/api/suppliers', [
                'name' => 'Papelaria Central',
                'material_ids' => [$papelao->id, $kraft->id],
            ])
            ->assertCreated();

        /*
         * O que a API ACEITA, a API DEVOLVE.
         *
         * A regra vem do MaterialResource, que aceitava a medida da folha na
         * escrita e não a devolvia na leitura — o formulário de edição relia a
         * resposta, encontrava o campo vazio e apagava o dado no primeiro salvar.
         * Um vínculo invisível na resposta faria a tela de fornecedores
         * desmarcar todos os materiais na próxima edição.
         */
        $nomes = array_column($resposta->json('data.materials'), 'name');

        $this->assertEqualsCanonicalizing(
            ['Papelão cinza 1,5mm', 'Papel kraft 120g'],
            $nomes,
        );

        $this->assertDatabaseCount('material_supplier', 2);
    }

    #[Test]
    public function a_listagem_traz_o_que_cada_fornecedor_vende(): void
    {
        $material = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'name' => 'Papelão ondulado E',
        ]);

        $fornecedor = Supplier::factory()->create(['tenant_id' => $this->empresa->id]);
        $fornecedor->materials()->attach($material);

        $resposta = $this->actingAs($this->usuario)->getJson('/api/suppliers')->assertOk();

        $this->assertSame(
            'Papelão ondulado E',
            $resposta->json('data.0.materials.0.name'),
        );

        /*
         * A tela desenha etiquetas com o nome. Custo de compra e gramatura não
         * têm o que fazer nessa resposta — e o usuário comum nem os enxerga no
         * recurso de materiais.
         */
        $this->assertArrayNotHasKey('cost_per_unit', $resposta->json('data.0.materials.0'));
        $this->assertArrayNotHasKey('pivot', $resposta->json('data.0.materials.0'));
    }

    #[Test]
    public function nao_da_para_vincular_material_de_outra_empresa(): void
    {
        /*
         * O ataque óbvio: o id vem do corpo da requisição, então basta chutar
         * números. Se a validação usasse `exists:materials,id` — que consulta a
         * tabela crua, por fora do TenantScope — o vínculo seria gravado, e o
         * nome do material da concorrente apareceria na tela de quem chutou.
         */
        $outra = Tenant::factory()->create();
        $alheio = Material::factory()->create(['tenant_id' => $outra->id]);

        $this->actingAs($this->usuario)
            ->postJson('/api/suppliers', [
                'name' => 'Fornecedor curioso',
                'material_ids' => [$alheio->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('material_ids.0');

        $this->assertDatabaseCount('material_supplier', 0);
    }

    #[Test]
    public function editar_o_telefone_nao_apaga_os_materiais(): void
    {
        /*
         * `sync([])` com a chave ausente desligaria tudo em silêncio. A tela
         * manda o formulário inteiro, mas a API é usada por mais gente do que a
         * tela — e perder o cadastro por causa de um PUT parcial é o tipo de
         * falha que ninguém nota até precisar do dado.
         */
        $material = Material::factory()->create(['tenant_id' => $this->empresa->id]);

        $fornecedor = Supplier::factory()->create(['tenant_id' => $this->empresa->id]);
        $fornecedor->materials()->attach($material);

        $this->actingAs($this->usuario)
            ->putJson("/api/suppliers/{$fornecedor->id}", ['phone' => '11999998888'])
            ->assertOk();

        $this->assertSame(1, $fornecedor->materials()->count());
    }

    #[Test]
    public function mandar_a_lista_vazia_desliga_todos_os_materiais(): void
    {
        // O outro lado da moeda do teste acima: quando a tela DIZ que não há
        // mais nenhum, a intenção é desligar — e ela precisa funcionar.
        $material = Material::factory()->create(['tenant_id' => $this->empresa->id]);

        $fornecedor = Supplier::factory()->create(['tenant_id' => $this->empresa->id]);
        $fornecedor->materials()->attach($material);

        $this->actingAs($this->usuario)
            ->putJson("/api/suppliers/{$fornecedor->id}", ['material_ids' => []])
            ->assertOk();

        $this->assertSame(0, $fornecedor->materials()->count());
    }

    #[Test]
    public function apagar_o_material_desfaz_o_vinculo_sem_derrubar_o_fornecedor(): void
    {
        /*
         * A tela de materiais desativa em vez de apagar, mas a exclusão de conta
         * (LGPD) e o cascade de empresa passam por aqui. Sem o
         * `cascadeOnDelete` no pivô, sobraria uma linha órfã apontando para um
         * material que não existe, e a listagem de fornecedores quebraria ao
         * tentar desenhar a etiqueta.
         */
        $material = Material::factory()->create(['tenant_id' => $this->empresa->id]);

        $fornecedor = Supplier::factory()->create(['tenant_id' => $this->empresa->id]);
        $fornecedor->materials()->attach($material);

        $material->delete();

        $this->assertDatabaseCount('material_supplier', 0);
        $this->assertDatabaseHas('suppliers', ['id' => $fornecedor->id]);
    }
}

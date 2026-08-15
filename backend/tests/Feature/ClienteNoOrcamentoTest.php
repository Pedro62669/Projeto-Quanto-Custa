<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O cadastro de clientes deixa de ser enfeite.
 *
 * O orçamento guardava o cliente como TEXTO LIVRE, e `quotes.client_id` existia
 * sem nunca ser preenchido na gravação. Na prática "Papelaria Silva" digitada
 * três vezes virava três clientes, e quem estava no cadastro nunca acumulava
 * histórico — a única ponte era `promote-client`, de mão única.
 *
 * Os dois campos convivem de propósito, e é isso que a maioria destes testes
 * verifica: o id dá histórico e sobrevive a uma correção de nome; o texto é a
 * fotografia do que foi escrito na proposta.
 */
class ClienteNoOrcamentoTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Material $papelao;

    protected function setUp(): void
    {
        parent::setUp();

        CostSetting::factory()->create();
        $this->usuario = User::factory()->create();
        $this->papelao = Material::factory()->create();
    }

    /** @param array<string, mixed> $extra */
    private function payload(array $extra = []): array
    {
        return [
            'material_id' => $this->papelao->id,
            'width_mm' => 300, 'height_mm' => 100, 'depth_mm' => 200,
            'quantity' => 10,
            'production_minutes_per_unit' => 0,
            'profit_margin_percent' => 0,
            'client_name' => 'Digitado à mão',
            ...$extra,
        ];
    }

    /* ── A ligação ─────────────────────────────────────────────────────── */

    #[Test]
    public function o_orcamento_nasce_ligado_ao_cliente_escolhido(): void
    {
        $cliente = Client::factory()->create(['name' => 'Papelaria Silva']);

        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->payload(['client_id' => $cliente->id]))
            ->assertCreated()
            ->assertJsonPath('data.client.id', $cliente->id);

        $this->assertSame($cliente->id, Quote::latest('id')->first()->client_id);
    }

    #[Test]
    public function o_cadastro_manda_no_nome_gravado(): void
    {
        /*
         * O navegador enviou "Digitado à mão" junto do id. O servidor descarta o
         * texto e copia do registro: se os dois divergissem, a proposta impressa
         * diria uma coisa e a ficha do cliente, outra.
         */
        $cliente = Client::factory()->create([
            'name' => 'Papelaria Silva',
            'email' => 'compras@silva.com.br',
            'cpf_cnpj' => '12345678000190',
        ]);

        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->payload(['client_id' => $cliente->id]))
            ->assertCreated()
            ->assertJsonPath('data.client.name', 'Papelaria Silva')
            ->assertJsonPath('data.client.email', 'compras@silva.com.br')
            ->assertJsonPath('data.client.document', '12345678000190');
    }

    #[Test]
    public function renomear_o_cadastro_nao_reescreve_a_proposta(): void
    {
        $cliente = Client::factory()->create(['name' => 'Papelaria Silva']);

        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->payload(['client_id' => $cliente->id]))
            ->assertCreated();

        $cliente->update(['name' => 'Silva Embalagens ME']);

        // O texto é fotografia: o cliente assinou uma proposta que dizia
        // "Papelaria Silva", e corrigir o cadastro hoje não muda aquele papel.
        $quote = Quote::latest('id')->first();
        $this->assertSame('Papelaria Silva', $quote->client_name);

        // Mas o vínculo continua — é por ele que o histórico se mantém inteiro.
        $this->assertSame($cliente->id, $quote->client_id);
    }

    #[Test]
    public function sem_client_id_o_orcamento_continua_avulso(): void
    {
        // O caminho mais comum na cartonagem: um nome e um WhatsApp. Exigir
        // cadastro completo para orçar travaria justamente a venda rápida.
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.client.id', null)
            ->assertJsonPath('data.client.name', 'Digitado à mão');
    }

    /* ── Isolamento entre empresas ─────────────────────────────────────── */

    #[Test]
    public function cliente_de_outra_empresa_nao_e_aceito(): void
    {
        /*
         * A razão de `client_id` NÃO usar `Rule::exists`: a regra consulta a
         * tabela crua, por fora do TenantScope, e aprovaria este id. Quem
         * recusa é o findOrFail escopado no controller.
         */
        $vizinha = Tenant::factory()->create();
        $alheio = Client::factory()->create(['tenant_id' => $vizinha->id]);

        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->payload(['client_id' => $alheio->id]))
            ->assertNotFound();

        $this->assertSame(0, Quote::count());
    }

    /* ── O histórico ───────────────────────────────────────────────────── */

    #[Test]
    public function a_listagem_filtra_pelo_cadastro(): void
    {
        $silva = Client::factory()->create(['name' => 'Papelaria Silva']);
        $outro = Client::factory()->create(['name' => 'Gráfica Central']);

        foreach ([$silva->id, $silva->id, $outro->id] as $id) {
            $this->actingAs($this->usuario)
                ->postJson('/api/quotes', $this->payload(['client_id' => $id]))
                ->assertCreated();
        }

        $encontrados = $this->actingAs($this->usuario)
            ->getJson("/api/quotes?client_id={$silva->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $encontrados);
    }

    #[Test]
    public function o_filtro_por_id_e_o_por_nome_sao_coisas_diferentes(): void
    {
        /*
         * `?client=silva` procura TEXTO e acha o avulso também — é o único
         * caminho para os orçamentos que nasceram sem cadastro. `?client_id=`
         * traz só o que está ligado. Os dois convivem porque respondem
         * perguntas diferentes.
         */
        $silva = Client::factory()->create(['name' => 'Papelaria Silva']);

        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->payload(['client_id' => $silva->id]))
            ->assertCreated();

        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->payload(['client_name' => 'Papelaria Silva']))
            ->assertCreated();

        $porNome = $this->actingAs($this->usuario)
            ->getJson('/api/quotes?client=silva')->assertOk()->json('data');

        $porId = $this->actingAs($this->usuario)
            ->getJson("/api/quotes?client_id={$silva->id}")->assertOk()->json('data');

        $this->assertCount(2, $porNome, 'A busca por texto acha o avulso também.');
        $this->assertCount(1, $porId, 'O filtro por cadastro só traz o que foi ligado.');
    }

    #[Test]
    public function o_movimento_do_caixa_filtra_pelo_cliente(): void
    {
        $cliente = Client::factory()->create();

        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->payload(['client_id' => $cliente->id]))
            ->assertCreated();

        // Aprovar é o que lança a venda no caixa, já com o dono.
        $quote = Quote::latest('id')->first();
        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/approve")
            ->assertOk();

        $lancamentos = $this->actingAs($this->usuario)
            ->getJson("/api/transactions?client_id={$cliente->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $lancamentos);
    }
}

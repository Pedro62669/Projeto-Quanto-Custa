<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProductKind;
use App\Enums\QuoteStatus;
use App\Enums\TransactionCategory;
use App\Models\Client;
use App\Models\CostSetting;
use App\Models\Installment;
use App\Models\Material;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Produtos deixa de ser ilha.
 *
 * A tabela guardava custo, preço, estoque e margem, e tinha ZERO relações — nem
 * com orçamento, nem com cliente, nem com o caixa. O sinal de que a ligação era
 * prevista e nunca construída: `TransactionCategory::ProductSale` existe desde
 * a Fase 4 e nunca fora usado por linha nenhuma de código.
 *
 * Duas pontas foram ligadas. A caixa aprovada vira produto de catálogo pelo
 * preço que o cliente aceitou; e vender um produto lança no caixa e baixa o
 * estoque numa transação só, em vez de duas ações separadas que ninguém
 * garantia acontecerem juntas.
 */
class ProdutoNoCaixaTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        CostSetting::factory()->create();
        $this->usuario = User::factory()->create();
    }

    private function orcamentoAprovado(): Quote
    {
        $quote = Quote::factory()->create([
            'user_id' => $this->usuario->id,
            'material_id' => Material::factory()->create()->id,
            'status' => QuoteStatus::Draft,
        ]);

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/approve")
            ->assertOk();

        return $quote->fresh();
    }

    /* ── A caixa vira produto ──────────────────────────────────────────── */

    #[Test]
    public function o_orcamento_aprovado_vira_caixa_no_catalogo(): void
    {
        $quote = $this->orcamentoAprovado();

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/publish-product")
            ->assertCreated()
            ->assertJsonPath('data.kind', ProductKind::Box->value)
            ->assertJsonPath('data.quote_id', $quote->id);

        $produto = Product::query()->firstOrFail();

        // O preço é o que a proposta praticou, não uma nova simulação: se o
        // papelão encarecer, quem republicar decide.
        $this->assertSame((float) $quote->unit_price, $produto->sale_price);
        $this->assertSame((float) $quote->unit_cost, $produto->cost_price);

        // O nome carrega a referência: duas caixas 300×80×250 para clientes
        // diferentes não podem virar linhas indistinguíveis na lista.
        $this->assertStringContainsString($quote->reference, $produto->name);
    }

    #[Test]
    public function publicar_nao_semeia_estoque(): void
    {
        // O lote foi entregue a quem o encomendou. Dizer que ele está na
        // prateleira faria a primeira venda de balcão prometer o que não há.
        $quote = $this->orcamentoAprovado();

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/publish-product")
            ->assertCreated()
            ->assertJsonPath('data.stock_quantity', 0);
    }

    #[Test]
    public function rascunho_nao_vira_produto(): void
    {
        /*
         * Rascunho é simulação. Pôr no catálogo um preço que ninguém aceitou
         * seria vender por um número que nunca foi negociado.
         */
        $quote = Quote::factory()->create([
            'user_id' => $this->usuario->id,
            'material_id' => Material::factory()->create()->id,
            'status' => QuoteStatus::Draft,
        ]);

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/publish-product")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(0, Product::count());
    }

    #[Test]
    public function publicar_duas_vezes_devolve_o_mesmo_produto(): void
    {
        /*
         * Duas entradas do mesmo modelo teriam estoques separados, e a segunda
         * venda baixaria o errado. Mesma idempotência de `promoteClient`.
         */
        $quote = $this->orcamentoAprovado();

        $primeiro = $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/publish-product")
            ->assertCreated()->json('data.id');

        $segundo = $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/publish-product")
            ->assertOk()->json('data.id');

        $this->assertSame($primeiro, $segundo);
        $this->assertSame(1, Product::count());
    }

    #[Test]
    public function o_formulario_so_cria_mercadoria(): void
    {
        /*
         * Caixa pronta nasce de orçamento. Deixar o formulário declarar
         * `kind: box` produziria uma caixa sem proposta por trás: o link para a
         * origem apontaria para lugar nenhum, e um preço digitado se passaria
         * por um preço calculado.
         */
        $this->actingAs($this->usuario)
            ->postJson('/api/products', [
                'name' => 'Fita de cetim 15mm',
                'sale_price' => 4.50,
                'kind' => 'box',
                'quote_id' => 999,
            ])
            ->assertCreated()
            ->assertJsonPath('data.kind', ProductKind::Merchandise->value)
            ->assertJsonPath('data.quote_id', null);
    }

    /* ── A venda ───────────────────────────────────────────────────────── */

    #[Test]
    public function vender_lanca_no_caixa_e_baixa_o_estoque(): void
    {
        $produto = Product::factory()->create([
            'name' => 'Sacola kraft',
            'sale_price' => 12.50,
            'stock_quantity' => 20,
        ]);

        $this->actingAs($this->usuario)
            ->postJson("/api/products/{$produto->id}/sell", ['quantity' => 3])
            ->assertCreated();

        // Estoque baixado.
        $this->assertSame(17, $produto->fresh()->stock_quantity);

        // E o dinheiro no livro-caixa, com a categoria que existia sem uso.
        $lancamento = Transaction::query()->firstOrFail();
        $this->assertSame(TransactionCategory::ProductSale, $lancamento->category);
        $this->assertSame(37.5, (float) $lancamento->amount);
        $this->assertSame($produto->id, $lancamento->product_id);
    }

    #[Test]
    public function a_venda_gera_parcelas_pelo_mesmo_motor_da_aprovacao(): void
    {
        // Uma venda a prazo do catálogo aparece no fluxo de caixa igual a uma
        // venda de embalagem, porque para o dinheiro elas são a mesma coisa.
        $produto = Product::factory()->create(['sale_price' => 30.00, 'stock_quantity' => 10]);

        $this->actingAs($this->usuario)
            ->postJson("/api/products/{$produto->id}/sell", [
                'quantity' => 2,
                'installments' => 3,
            ])
            ->assertCreated();

        $this->assertSame(3, Installment::count());
        $this->assertSame(60.0, (float) Installment::sum('amount'));
    }

    #[Test]
    public function o_preco_pode_ser_ajustado_sem_mexer_no_cadastro(): void
    {
        // Desconto de balcão. Editar o produto mudaria o preço de todas as
        // vendas seguintes por causa de uma.
        $produto = Product::factory()->create(['sale_price' => 25.00, 'stock_quantity' => 10]);

        $this->actingAs($this->usuario)
            ->postJson("/api/products/{$produto->id}/sell", [
                'quantity' => 2,
                'unit_price' => 20.00,
            ])
            ->assertCreated();

        $this->assertSame(40.0, (float) Transaction::query()->firstOrFail()->amount);

        // O cadastro segue intacto para a próxima venda.
        $this->assertSame(25.0, $produto->fresh()->sale_price);
    }

    #[Test]
    public function vender_mais_que_o_estoque_e_recusado(): void
    {
        /*
         * A coluna aceita negativo — é o dado verdadeiro de uma contagem que
         * revelou falta. Deixar a VENDA cavar o buraco é outra coisa: o sistema
         * confirmaria uma entrega que a prateleira não tem, e o erro só
         * apareceria na hora de despachar.
         */
        $produto = Product::factory()->create(['stock_quantity' => 2]);

        $this->actingAs($this->usuario)
            ->postJson("/api/products/{$produto->id}/sell", ['quantity' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(2, $produto->fresh()->stock_quantity);
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function a_venda_pode_ter_dono(): void
    {
        $cliente = Client::factory()->create();
        $produto = Product::factory()->create(['stock_quantity' => 5]);

        $this->actingAs($this->usuario)
            ->postJson("/api/products/{$produto->id}/sell", [
                'quantity' => 1,
                'client_id' => $cliente->id,
            ])
            ->assertCreated();

        // E aparece na ficha dele, ao lado dos orçamentos.
        $lancamentos = $this->actingAs($this->usuario)
            ->getJson("/api/transactions?client_id={$cliente->id}")
            ->assertOk()->json('data');

        $this->assertCount(1, $lancamentos);
    }

    #[Test]
    public function cliente_de_outra_empresa_nao_e_aceito_na_venda(): void
    {
        $vizinha = Tenant::factory()->create();
        $alheio = Client::factory()->create(['tenant_id' => $vizinha->id]);
        $produto = Product::factory()->create(['stock_quantity' => 5]);

        $this->actingAs($this->usuario)
            ->postJson("/api/products/{$produto->id}/sell", [
                'quantity' => 1,
                'client_id' => $alheio->id,
            ])
            ->assertNotFound();

        // Nada gravado: a recusa acontece antes da transação de banco.
        $this->assertSame(0, Transaction::count());
        $this->assertSame(5, $produto->fresh()->stock_quantity);
    }

    /* ── A listagem ────────────────────────────────────────────────────── */

    #[Test]
    public function a_listagem_separa_caixa_de_mercadoria(): void
    {
        $quote = $this->orcamentoAprovado();

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/publish-product")->assertCreated();

        Product::factory()->create(['name' => 'Laço de cetim']);

        $caixas = $this->actingAs($this->usuario)
            ->getJson('/api/products?kind=box')->assertOk()->json('data');

        $mercadorias = $this->actingAs($this->usuario)
            ->getJson('/api/products?kind=merchandise')->assertOk()->json('data');

        $this->assertCount(1, $caixas);
        $this->assertCount(1, $mercadorias);

        // A caixa traz a referência do orçamento junto: é o atalho para a
        // proposta que definiu aquele preço.
        $this->assertSame($quote->reference, $caixas[0]['quote']['reference']);
    }
}

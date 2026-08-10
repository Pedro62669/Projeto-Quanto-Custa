<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InstallmentStatus;
use App\Enums\QuoteStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Installment;
use App\Models\Material;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\FinancialEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Livro caixa: parcelamento, aprovação de orçamento e isolamento.
 *
 * O centro daqui é o CENTAVO. Um caixa que perde um centavo por venda
 * parcelada nunca fecha com o extrato, e a diferença é pequena demais para
 * alguém notar antes de virar uma discussão com o contador.
 */
class CashFlowTest extends TestCase
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

    private function motor(): FinancialEngine
    {
        return app(FinancialEngine::class);
    }

    private function transacao(float $valor, ?Carbon $data = null): Transaction
    {
        return Transaction::factory()->create([
            'tenant_id' => $this->empresa->id,
            'amount' => $valor,
            'transaction_date' => $data ?? now(),
        ]);
    }

    /* ── O centavo ─────────────────────────────────────────────────────── */

    #[Test]
    public function as_parcelas_somam_exatamente_o_total(): void
    {
        $this->actingAs($this->usuario);

        // R$ 100 em 3× = 33,3333... A divisão ingênua daria 33,33 × 3 = 99,99.
        $transacao = $this->transacao(100.00);

        $parcelas = $this->motor()->generateInstallments($transacao, 3);

        $valores = array_map(fn (Installment $p): float => $p->amount, $parcelas);

        $this->assertSame([33.33, 33.33, 33.34], $valores);
        $this->assertSame(100.00, round(array_sum($valores), 2));
    }

    #[Test]
    public function a_sobra_vai_para_a_ultima_parcela(): void
    {
        $this->actingAs($this->usuario);

        // R$ 10 em 3×: 3,33 + 3,33 + 3,34.
        $parcelas = $this->motor()->generateInstallments($this->transacao(10.00), 3);

        /*
         * Na última e não na primeira: o cliente vê o valor cheio nas iniciais
         * e a diferença aparece no fim, onde ninguém a confunde com erro de
         * cálculo. É a praxe do carnê brasileiro.
         */
        $this->assertSame(3.34, end($parcelas)->amount);
        $this->assertSame(3.33, $parcelas[0]->amount);
    }

    #[Test]
    public function divisao_exata_nao_inventa_sobra(): void
    {
        $this->actingAs($this->usuario);

        $parcelas = $this->motor()->generateInstallments($this->transacao(300.00), 3);

        foreach ($parcelas as $parcela) {
            $this->assertSame(100.00, $parcela->amount);
        }
    }

    /* ── Os vencimentos ────────────────────────────────────────────────── */

    #[Test]
    public function os_vencimentos_sao_mensais_consecutivos(): void
    {
        $this->actingAs($this->usuario);

        $transacao = $this->transacao(300.00, Carbon::parse('2026-03-10'));

        $parcelas = $this->motor()->generateInstallments($transacao, 3);

        $this->assertSame('2026-03-10', $parcelas[0]->due_date->toDateString());
        $this->assertSame('2026-04-10', $parcelas[1]->due_date->toDateString());
        $this->assertSame('2026-05-10', $parcelas[2]->due_date->toDateString());
    }

    #[Test]
    public function venda_no_dia_31_nao_transborda_de_mes(): void
    {
        $this->actingAs($this->usuario);

        // 31/01 + 1 mês seria 31/02, que não existe.
        $transacao = $this->transacao(300.00, Carbon::parse('2026-01-31'));

        $parcelas = $this->motor()->generateInstallments($transacao, 3);

        /*
         * addMonthsNoOverflow puxa para o último dia do mês em vez de
         * transbordar para 03/03 — o comportamento de qualquer carnê.
         */
        $this->assertSame('2026-02-28', $parcelas[1]->due_date->toDateString());
        $this->assertSame('2026-03-31', $parcelas[2]->due_date->toDateString());
    }

    #[Test]
    public function parcelamento_invalido_falha_de_forma_explicita(): void
    {
        $this->actingAs($this->usuario);

        $this->expectException(\DomainException::class);

        $this->motor()->generateInstallments($this->transacao(100.00), 0);
    }

    /* ── Aprovação de orçamento ────────────────────────────────────────── */

    private function orcamento(float $totalPrice = 3000.00): Quote
    {
        $material = Material::factory()->create(['tenant_id' => $this->empresa->id]);

        return Quote::create([
            'tenant_id' => $this->empresa->id,
            'user_id' => $this->usuario->id,
            'material_id' => $material->id,
            'box_model' => 'rsc',
            'width_mm' => 300, 'height_mm' => 200, 'depth_mm' => 150,
            'quantity' => 100, 'waste_percent' => 10,
            'production_minutes_per_unit' => 2.5, 'profit_margin_percent' => 30,
            'client_name' => 'Ana Cartonagem',
            'client_email' => 'ana@teste.com',
            'area_m2_per_unit' => 0.3, 'area_m2_total' => 30.0,
            'material_cost' => 8.00, 'wrap_cost' => 0.0, 'hardware_cost' => 0.0,
            'labor_cost' => 1.0, 'machine_cost' => 1.0, 'energy_cost' => 0.5,
            'overhead_cost' => 0.0, 'unit_cost' => 10.5, 'unit_price' => 30.0,
            'total_cost' => 1050.0, 'total_price' => $totalPrice,
            'profit_amount' => 1950.0, 'pricing_snapshot' => [],
        ]);
    }

    #[Test]
    public function aprovar_o_orcamento_lanca_a_venda_no_caixa(): void
    {
        $quote = $this->orcamento(3000.00);

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/approve", ['installments' => 3])
            ->assertOk()
            ->assertJsonPath('data.quote.status', 'approved')
            ->assertJsonCount(3, 'data.transaction.installments');

        $transacao = Transaction::query()->sole();

        $this->assertSame(TransactionType::Entry, $transacao->type);
        $this->assertSame(TransactionCategory::QuoteSale, $transacao->category);
        $this->assertSame(3000.00, $transacao->amount);
        $this->assertSame($quote->id, $transacao->quote_id);

        $this->assertSame(3000.00, round((float) Installment::query()->sum('amount'), 2));
    }

    #[Test]
    public function aprovar_duas_vezes_nao_dobra_o_faturamento(): void
    {
        $quote = $this->orcamento();

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/approve")->assertOk();

        /*
         * O erro que este teste existe para impedir: dois cliques no botão
         * lançariam a mesma venda duas vezes e dobrariam o mês. 422 explícito é
         * melhor que uma guarda silenciosa — quem clicou precisa saber que a
         * primeira funcionou.
         */
        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/approve")
            ->assertUnprocessable();

        $this->assertSame(1, Transaction::query()->count());
    }

    #[Test]
    public function sem_parcelas_informadas_a_venda_e_a_vista(): void
    {
        $quote = $this->orcamento(1500.00);

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/approve")->assertOk();

        $parcela = Installment::query()->sole();

        $this->assertSame(1, $parcela->installment_number);
        $this->assertSame(1, $parcela->total_installments);
        $this->assertSame(1500.00, $parcela->amount);
    }

    #[Test]
    public function a_venda_de_orcamento_nao_pode_ser_lancada_a_mao(): void
    {
        /*
         * Se pudesse, a mesma venda entraria duas vezes: uma pela aprovação e
         * outra pelo lançamento manual. A margem de contribuição contaria o
         * dobro de receita com o mesmo custo variável.
         */
        $this->actingAs($this->usuario)
            ->postJson('/api/transactions', [
                'type' => 'entry',
                'category' => 'quote_sale',
                'amount' => 1000,
                'description' => 'Tentativa manual',
                'transaction_date' => now()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');
    }

    /* ── Quitação ──────────────────────────────────────────────────────── */

    #[Test]
    public function quitar_registra_a_data_do_pagamento(): void
    {
        $this->actingAs($this->usuario);
        $parcelas = $this->motor()->generateInstallments($this->transacao(300.00), 3);

        $this->actingAs($this->usuario)
            ->postJson("/api/installments/{$parcelas[0]->id}/settle", [
                'payment_date' => now()->subDays(3)->toDateString(),
            ])
            ->assertOk();

        $parcela = $parcelas[0]->fresh();

        $this->assertSame(InstallmentStatus::Completed, $parcela->status);
        $this->assertSame(now()->subDays(3)->toDateString(), $parcela->payment_date->toDateString());
    }

    #[Test]
    public function nao_da_para_registrar_pagamento_no_futuro(): void
    {
        $this->actingAs($this->usuario);
        $parcelas = $this->motor()->generateInstallments($this->transacao(100.00), 1);

        $this->actingAs($this->usuario)
            ->postJson("/api/installments/{$parcelas[0]->id}/settle", [
                'payment_date' => now()->addWeek()->toDateString(),
            ])
            ->assertUnprocessable();
    }

    #[Test]
    public function desfazer_a_quitacao_devolve_a_parcela_ao_aberto(): void
    {
        $this->actingAs($this->usuario);
        $parcelas = $this->motor()->generateInstallments($this->transacao(100.00), 1);
        $parcelas[0]->settle();

        $this->actingAs($this->usuario)
            ->deleteJson("/api/installments/{$parcelas[0]->id}/settle")
            ->assertOk();

        $parcela = $parcelas[0]->fresh();

        $this->assertSame(InstallmentStatus::Pending, $parcela->status);
        $this->assertNull($parcela->payment_date);
    }

    /* ── Isolamento ────────────────────────────────────────────────────── */

    #[Test]
    public function as_parcelas_de_outra_empresa_nao_aparecem(): void
    {
        $vizinha = Tenant::factory()->create();

        $alheia = Transaction::factory()->create([
            'tenant_id' => $vizinha->id,
            'amount' => 90000.00,
        ]);

        Installment::create([
            'tenant_id' => $vizinha->id,
            'transaction_id' => $alheia->id,
            'installment_number' => 1,
            'total_installments' => 1,
            'amount' => 90000.00,
            'due_date' => now(),
        ]);

        $this->actingAs($this->usuario);
        $this->motor()->generateInstallments($this->transacao(300.00), 1);

        /*
         * O motivo de `installments` carregar tenant_id denormalizado: o painel
         * consulta parcelas DIRETAMENTE, e o TenantScope só filtra a tabela
         * consultada. Sem a coluna, os R$ 90.000 da vizinha entrariam no
         * faturamento desta empresa.
         */
        $this->assertSame(1, Installment::query()->count());
        $this->assertSame(300.00, round((float) Installment::query()->sum('amount'), 2));
    }

    #[Test]
    public function o_cliente_de_outra_empresa_nao_e_vinculavel(): void
    {
        $vizinha = Tenant::factory()->create();
        $alheio = Client::factory()->create(['tenant_id' => $vizinha->id]);

        $quote = $this->orcamento();

        /*
         * 404 e não 422: quem barra é o findOrFail ESCOPADO do controller, não
         * a validação. `Rule::exists` consulta a tabela sem passar pelo
         * TenantScope — com ela, este id passaria e seria gravado no orçamento
         * e na transação. Escrita cruzada, a metade silenciosa do IDOR.
         */
        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/approve", ['client_id' => $alheio->id])
            ->assertNotFound();

        $this->assertSame(0, Transaction::query()->count());
    }

    /* ── Cadastros ─────────────────────────────────────────────────────── */

    #[Test]
    public function o_cpf_e_unico_por_empresa_e_nao_globalmente(): void
    {
        $vizinha = Tenant::factory()->create();
        Client::factory()->create(['tenant_id' => $vizinha->id, 'cpf_cnpj' => '12345678901']);

        /*
         * O mesmo cliente pode comprar de duas cartonagens. Um único global
         * impediria a segunda de cadastrá-lo — e o erro apareceria como "CPF já
         * existe" para um cliente que ela nunca viu.
         */
        $this->actingAs($this->usuario)
            ->postJson('/api/clients', ['name' => 'Ana', 'cpf_cnpj' => '12345678901'])
            ->assertCreated();
    }

    #[Test]
    public function promover_o_cliente_reaproveita_o_que_ja_foi_digitado(): void
    {
        $quote = $this->orcamento();

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/promote-client")
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ana Cartonagem')
            ->assertJsonPath('data.email', 'ana@teste.com');

        $this->assertNotNull($quote->fresh()->client_id);
    }

    #[Test]
    public function o_snapshot_do_cliente_sobrevive_a_edicao_do_cadastro(): void
    {
        $quote = $this->orcamento();

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/promote-client")->assertCreated();

        $client = Client::query()->sole();

        $this->actingAs($this->usuario)
            ->putJson("/api/clients/{$client->id}", ['name' => 'Ana Embalagens ME'])
            ->assertOk();

        /*
         * Corrigir o cadastro amanhã não pode reescrever a proposta assinada
         * ontem — mesma filosofia do pricing_snapshot.
         */
        $this->assertSame('Ana Cartonagem', $quote->fresh()->client_name);
        $this->assertSame('Ana Embalagens ME', $client->fresh()->name);
    }

    #[Test]
    public function o_status_aprovado_fecha_o_orcamento(): void
    {
        $quote = $this->orcamento();

        $this->assertSame(QuoteStatus::Draft, $quote->status);
        $this->assertFalse($quote->status->isClosed());

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/approve")->assertOk();

        $this->assertTrue($quote->fresh()->status->isClosed());
    }

    #[Test]
    public function a_rota_de_caixa_exige_autenticacao(): void
    {
        $this->getJson('/api/financial/dashboard')->assertUnauthorized();
        $this->getJson('/api/clients')->assertUnauthorized();
        $this->postJson('/api/transactions')->assertUnauthorized();
    }
}

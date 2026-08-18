<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Corrigir um lançamento sem apagar o histórico.
 *
 * A rota não existia: o apiResource registrava index, store, show e destroy.
 * Errou o valor, apagava e relançava — perdendo a data original e a numeração
 * das parcelas.
 *
 * Pior que a ausência: `api.finance.transactions.update` EXISTIA no cliente,
 * porque a fábrica de CRUD a gera para todo recurso, e apontava para uma rota
 * inexistente. Quem a chamasse recebia 405.
 */
class LancamentoEditavelTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create();
    }

    /** @param array<string, mixed> $extra */
    private function lancar(array $extra = []): Transaction
    {
        $this->actingAs($this->usuario)->postJson('/api/transactions', [
            'type' => 'entry',
            'category' => 'other',
            'amount' => 900.00,
            'description' => 'Venda de balcão',
            'transaction_date' => '2026-08-10',
            ...$extra,
        ])->assertCreated();

        return Transaction::latest('id')->firstOrFail();
    }

    /* ── A correção ────────────────────────────────────────────────────── */

    #[Test]
    public function o_valor_pode_ser_corrigido(): void
    {
        $lancamento = $this->lancar();

        $this->actingAs($this->usuario)
            ->putJson("/api/transactions/{$lancamento->id}", ['amount' => 750.00])
            ->assertOk()
            ->assertJsonPath('data.amount', fn ($v) => (float) $v === 750.0);

        // O registro é o MESMO: a data original e o id sobrevivem, que é o
        // ponto de corrigir em vez de apagar e relançar.
        $this->assertSame(1, Transaction::count());
        $this->assertSame('2026-08-10', $lancamento->fresh()->transaction_date->format('Y-m-d'));
    }

    #[Test]
    public function as_parcelas_acompanham_o_novo_valor(): void
    {
        /*
         * Sem redistribuir, o lançamento diria R$ 600 e as parcelas somariam
         * R$ 900. O painel financeiro lê as PARCELAS e a lista lê o lançamento:
         * duas telas do mesmo dinheiro discordando, e cada uma parecendo certa
         * sozinha.
         */
        $lancamento = $this->lancar(['installments' => 3]);

        $this->assertSame(900.0, (float) Installment::sum('amount'));

        $this->actingAs($this->usuario)
            ->putJson("/api/transactions/{$lancamento->id}", ['amount' => 600.00])
            ->assertOk();

        $this->assertSame(600.0, (float) Installment::sum('amount'));
        $this->assertSame(3, Installment::count());
    }

    #[Test]
    public function os_vencimentos_nao_sao_renegociados(): void
    {
        // Quantidade e datas foram combinadas com o cliente. Recriá-las
        // transformaria uma correção de digitação numa renegociação.
        $lancamento = $this->lancar(['installments' => 2, 'first_due_date' => '2026-09-05']);

        $vencimentosAntes = Installment::orderBy('installment_number')
            ->pluck('due_date')->map(fn ($d) => $d->format('Y-m-d'))->all();

        $this->actingAs($this->usuario)
            ->putJson("/api/transactions/{$lancamento->id}", ['amount' => 400.00])
            ->assertOk();

        $vencimentosDepois = Installment::orderBy('installment_number')
            ->pluck('due_date')->map(fn ($d) => $d->format('Y-m-d'))->all();

        $this->assertSame($vencimentosAntes, $vencimentosDepois);
    }

    #[Test]
    public function a_sobra_dos_centavos_vai_para_a_ultima_parcela(): void
    {
        // R$ 100 em 3× = 33,33 + 33,33 + 33,34. A soma tem que fechar EXATO com
        // o total; um centavo perdido por lançamento vira diferença de caixa no
        // fechamento do mês.
        $lancamento = $this->lancar(['installments' => 3]);

        $this->actingAs($this->usuario)
            ->putJson("/api/transactions/{$lancamento->id}", ['amount' => 100.00])
            ->assertOk();

        $valores = Installment::orderBy('installment_number')->pluck('amount')
            ->map(fn ($v) => (float) $v)->all();

        $this->assertSame([33.33, 33.33, 33.34], $valores);
        $this->assertSame(100.0, array_sum($valores));
    }

    /* ── O que não se edita ────────────────────────────────────────────── */

    #[Test]
    public function lancamento_com_parcela_baixada_nao_muda_de_valor(): void
    {
        /*
         * Parcela baixada é dinheiro conferido contra o extrato. Reescrever o
         * valor por cima disso deixaria o caixa dizendo um número que a
         * conciliação já provou ser outro — e em silêncio, porque a parcela
         * continuaria marcada como paga.
         */
        $lancamento = $this->lancar(['installments' => 2]);
        $parcela = Installment::orderBy('installment_number')->first();

        $this->actingAs($this->usuario)
            ->postJson("/api/installments/{$parcela->id}/settle")
            ->assertOk();

        $this->actingAs($this->usuario)
            ->putJson("/api/transactions/{$lancamento->id}", ['amount' => 100.00])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(900.0, (float) $lancamento->fresh()->amount);
    }

    #[Test]
    public function estornar_a_baixa_devolve_a_edicao(): void
    {
        // O caminho que a mensagem de erro indica: estorne primeiro. O estorno
        // fica registrado; a reescrita silenciosa não ficaria.
        $lancamento = $this->lancar(['installments' => 2]);
        $parcela = Installment::orderBy('installment_number')->first();

        $this->actingAs($this->usuario)
            ->postJson("/api/installments/{$parcela->id}/settle")->assertOk();

        $this->actingAs($this->usuario)
            ->deleteJson("/api/installments/{$parcela->id}/settle")->assertOk();

        $this->actingAs($this->usuario)
            ->putJson("/api/transactions/{$lancamento->id}", ['amount' => 100.00])
            ->assertOk();
    }

    #[Test]
    public function tipo_e_categoria_nao_sao_editaveis(): void
    {
        /*
         * Trocar entrada por saída inverte o sinal do mês inteiro, e trocar a
         * categoria move dinheiro entre relatórios. As duas coisas são um
         * lançamento diferente, não uma correção — para isso existe excluir e
         * relançar, que deixa rastro.
         */
        $lancamento = $this->lancar();

        $this->actingAs($this->usuario)
            ->putJson("/api/transactions/{$lancamento->id}", [
                'type' => 'exit',
                'category' => 'fixed_cost',
                'description' => 'Corrigido',
            ])
            ->assertOk();

        $fresco = $lancamento->fresh();

        $this->assertSame('entry', $fresco->type->value);
        $this->assertSame('other', $fresco->category->value);

        // E o que É editável foi editado: a recusa é seletiva, não um bloqueio.
        $this->assertSame('Corrigido', $fresco->description);
    }

    /* ── Isolamento entre empresas ─────────────────────────────────────── */

    #[Test]
    public function cliente_de_outra_empresa_nao_e_aceito(): void
    {
        $vizinha = Tenant::factory()->create();
        $alheio = Client::factory()->create(['tenant_id' => $vizinha->id]);
        $lancamento = $this->lancar();

        $this->actingAs($this->usuario)
            ->putJson("/api/transactions/{$lancamento->id}", ['client_id' => $alheio->id])
            ->assertNotFound();

        $this->assertNull($lancamento->fresh()->client_id);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QuoteStatus;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Os quatro estados que a coluna declara e a interface não alcançava.
 *
 * `status` vale draft, sent, approved e rejected desde a primeira migration.
 * Dava para salvar rascunho e aprovar, e mais nada — enquanto a lista de
 * orçamentos oferecia filtro pelos quatro. Filtrar por "Enviado" ou "Recusado"
 * sempre voltava vazio, porque nada no sistema os produzia.
 *
 * O furo que apareceu ao abrir isso: `approved` era aceito no PUT, e um
 * orçamento marcado assim NÃO passava pelo QuoteApprovalController — nenhuma
 * venda no caixa, nenhuma parcela.
 */
class SituacaoDoOrcamentoTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        CostSetting::factory()->create();
        $this->usuario = User::factory()->create();
    }

    private function orcamento(QuoteStatus $status = QuoteStatus::Draft): Quote
    {
        return Quote::factory()->create([
            'user_id' => $this->usuario->id,
            'material_id' => Material::factory()->create()->id,
            'status' => $status,
        ]);
    }

    /* ── As transições que passam ──────────────────────────────────────── */

    #[Test]
    public function o_rascunho_pode_ser_marcado_como_enviado(): void
    {
        $quote = $this->orcamento();

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}", ['status' => 'sent'])
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');
    }

    #[Test]
    public function o_enviado_pode_ser_recusado(): void
    {
        // O cliente respondeu não. Sem este caminho, a única saída era excluir o
        // orçamento — e apagar a proposta apaga também o motivo de ela existir.
        $quote = $this->orcamento(QuoteStatus::Sent);

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}", ['status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    #[Test]
    public function o_recusado_volta_a_rascunho(): void
    {
        // Recusa não é fim de linha: o cliente pede desconto e a conversa segue.
        $quote = $this->orcamento(QuoteStatus::Rejected);

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}", ['status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
    }

    #[Test]
    public function o_filtro_por_situacao_passa_a_encontrar_o_enviado(): void
    {
        /*
         * O teste que descreve o defeito inteiro: a lista sempre teve o filtro,
         * e ele só devolvia vazio porque nada produzia o estado.
         */
        $quote = $this->orcamento();

        $vazio = $this->actingAs($this->usuario)
            ->getJson('/api/quotes?status=sent')->assertOk()->json('data');
        $this->assertCount(0, $vazio);

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}", ['status' => 'sent'])
            ->assertOk();

        $encontrados = $this->actingAs($this->usuario)
            ->getJson('/api/quotes?status=sent')->assertOk()->json('data');
        $this->assertCount(1, $encontrados);
    }

    /* ── As que não passam ─────────────────────────────────────────────── */

    #[Test]
    public function aprovar_pelo_put_e_recusado(): void
    {
        /*
         * O furo que esta fase fechou.
         *
         * `status: approved` no PUT marcava o orçamento como aprovado sem
         * lançar a venda no caixa e sem gerar parcela: o faturamento do mês
         * ficava menor que as vendas fechadas, e nada na tela denunciava.
         *
         * Aprovar tem endpoint próprio porque tem efeito colateral.
         */
        $quote = $this->orcamento();

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}", ['status' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(QuoteStatus::Draft, $quote->fresh()->status);
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function de_aprovado_nao_se_volta(): void
    {
        /*
         * A aprovação lançou a venda e gerou as parcelas. Devolver o orçamento a
         * rascunho deixaria o livro-caixa descrito por um documento que diz não
         * ter sido aprovado.
         */
        $quote = $this->orcamento();

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/approve")
            ->assertOk();

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}", ['status' => 'draft'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(QuoteStatus::Approved, $quote->fresh()->status);

        // E o lançamento continua de pé — é ele que a mensagem manda estornar.
        $this->assertSame(1, Transaction::count());
    }

    #[Test]
    public function a_observacao_do_aprovado_continua_editavel(): void
    {
        /*
         * Só a SITUAÇÃO trava. Anotar "cliente pediu entrega parcelada" num
         * orçamento já aprovado é registro de trabalho, não reescrita de
         * proposta — e recusar isso empurraria a anotação para fora do sistema.
         */
        $quote = $this->orcamento();

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/approve")
            ->assertOk();

        $this->actingAs($this->usuario)
            ->putJson("/api/quotes/{$quote->id}", ['notes' => 'Entrega em duas levas'])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Entrega em duas levas');
    }
}

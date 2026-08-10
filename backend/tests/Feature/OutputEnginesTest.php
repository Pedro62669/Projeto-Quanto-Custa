<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Production\QuotePdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Motores de saída: ficha técnica de produção e PDF comercial.
 *
 * O teste que sustenta esta fase é o da COERÊNCIA: o gabarito precisa
 * descrever a mesma caixa que foi precificada. Uma ficha com fórmula própria
 * produziria o pior defeito do sistema — o cliente paga uma caixa e a produção
 * corta outra —, e ele não apareceria em nenhum outro teste, porque a paridade
 * compara PHP com TS e nunca o preço com a ficha.
 */
class OutputEnginesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $usuario;

    private Material $cinza;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->completo()->create(['name' => 'Cartonagem Alfa']);

        $this->usuario = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::Admin,
        ]);

        CostSetting::factory()->create(['tenant_id' => $this->empresa->id]);

        $this->cinza = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'name' => 'Papelão cinza 1,9mm',
            'thickness_mm' => 1.9,
        ]);
    }

    private function orcamento(string $model = 'rigid_telescopic'): Quote
    {
        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes', [
                'material_id' => $this->cinza->id,
                'box_model' => $model,
                'width_mm' => 300, 'height_mm' => 200, 'depth_mm' => 150,
                'quantity' => 250,
                'waste_percent' => 10,
                'production_minutes_per_unit' => 12,
                'profit_margin_percent' => 30,
                'pricing_mode' => 'markup',
                'client_name' => 'Ana Presentes',
                'client_email' => 'ana@teste.com',
            ])->assertCreated();

        return Quote::query()->findOrFail($response->json('data.id'));
    }

    /* ── Coerência com o preço ─────────────────────────────────────────── */

    #[Test]
    public function o_fundo_cortado_tem_as_medidas_internas_vendidas(): void
    {
        $quote = $this->orcamento();

        $response = $this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->assertOk();

        $fundo = collect($response->json('data.cut_template.structure'))
            ->firstWhere('name', 'Base — fundo');

        /*
         * O teste central da fase. O documento da Fase 5 mandava cortar o fundo
         * em `C − 2·Ep`, tratando as medidas como EXTERNAS — mas o motor as
         * trata como INTERNAS desde sempre. Seguir o documento faria a produção
         * cortar 296,2 onde o cliente comprou 300, e a caixa sairia menor que a
         * vendida sem ninguém notar até a bancada.
         */
        // Inteiros: o JSON serializa 300.0 como 300.
        $this->assertSame(300, $fundo['width_mm']);
        $this->assertSame(150, $fundo['height_mm']);
    }

    #[Test]
    public function as_paredes_laterais_envolvem_a_espessura_das_outras(): void
    {
        $quote = $this->orcamento();

        $pecas = collect($this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->json('data.cut_template.structure'));

        $frente = $pecas->firstWhere('name', 'Base — parede frente/trás');
        $lateral = $pecas->firstWhere('name', 'Base — parede lateral');

        // Frente/trás: largura do fundo. Laterais: profundidade + 2 espessuras,
        // porque envolvem as outras duas. Trocar isso faz a caixa fechar torta.
        $this->assertSame(300, $frente['width_mm']);
        $this->assertSame(153.8, $lateral['width_mm']);
        $this->assertSame(2, $frente['quantity']);
        $this->assertSame(2, $lateral['quantity']);
    }

    #[Test]
    public function a_folha_de_revestimento_bate_com_a_area_precificada(): void
    {
        $quote = $this->orcamento();

        $folha = collect($this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->json('data.cut_template.wrap'))
            ->firstWhere('name', 'Revestimento externo da base');

        /*
         * A mesma conta de BlankCalculator::rigidWrapPanel():
         * 300 + 2×200 + 2×1,9 + 2×15 = 733,8 por 150 + 400 + 3,8 + 30 = 583,8.
         *
         * Se a ficha cortasse uma folha diferente da que foi cobrada, o
         * estoque não fecharia com o orçamento — e a diferença apareceria como
         * "sumiço" de papel, não como erro de cálculo.
         */
        $this->assertSame(733.8, $folha['width_mm']);
        $this->assertSame(583.8, $folha['height_mm']);
    }

    #[Test]
    public function a_espessura_sai_do_snapshot_e_nao_do_cadastro_atual(): void
    {
        $quote = $this->orcamento();

        // O papelão engorda no cadastro DEPOIS do orçamento fechado.
        $this->cinza->update(['thickness_mm' => 5.0]);

        $lateral = collect($this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->json('data.cut_template.structure'))
            ->firstWhere('name', 'Base — parede lateral');

        /*
         * A ficha continua mandando cortar a peça VENDIDA (150 + 2×1,9), e não
         * a que o cadastro descreve hoje (150 + 2×5). O snapshot é a fotografia
         * do que foi combinado; ignorá-lo faria a produção seguir um material
         * que o cliente não comprou.
         */
        $this->assertSame(153.8, $lateral['width_mm']);
    }

    /* ── A lista de separação ──────────────────────────────────────────── */

    #[Test]
    public function a_lista_de_separacao_multiplica_pelo_lote(): void
    {
        $quote = $this->orcamento();

        $linhas = collect($this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->json('data.picking_list'));

        $lateral = $linhas->firstWhere('piece', 'Base — parede lateral');

        /*
         * Quem vai ao estoque leva o pedido inteiro: 2 peças × 250 caixas.
         * Multiplicar de cabeça na frente da prateleira é como se erra a
         * retirada.
         */
        $this->assertSame(2, $lateral['per_unit']);
        $this->assertSame(500, $lateral['total']);
    }

    #[Test]
    public function a_ficha_avisa_que_o_forro_interno_nao_esta_no_preco(): void
    {
        $quote = $this->orcamento();

        $notas = implode(' ', $this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->json('data.cut_template.notes'));

        /*
         * O forro interno do fundo está no gabarito (a produção precisa dele)
         * mas NÃO no preço — o motor não o cobra. Listá-lo sem aviso mandaria
         * retirar do estoque um material que o cliente não pagou.
         */
        $this->assertStringContainsString('NÃO está incluído no preço', $notas);
    }

    /* ── A família da capa ─────────────────────────────────────────────── */

    #[Test]
    public function a_ficha_da_caixa_ima_descreve_a_capa_painel_a_painel(): void
    {
        $quote = $this->orcamento('rigid_magnet_side');

        $ficha = $this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->assertOk();

        $nomes = collect($ficha->json('data.cut_template.structure'))->pluck('name');

        $this->assertContains('Contracapa', $nomes);
        $this->assertContains('Lombada', $nomes);
        $this->assertContains('Aba do fecho magnético', $nomes);
        $this->assertContains('Aba lateral', $nomes);

        $notas = implode(' ', $ficha->json('data.cut_template.notes'));

        // A canaleta é montada na colagem, não cortada — e a polaridade do ímã
        // é o erro que só aparece depois de colado.
        $this->assertStringContainsString('Canaleta', $notas);
        $this->assertStringContainsString('POLARIDADE', $notas);
    }

    #[Test]
    public function a_caixa_dobrada_sai_como_chapa_unica(): void
    {
        $quote = $this->orcamento('rsc');

        $ficha = $this->actingAs($this->usuario)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->assertOk();

        $this->assertCount(1, $ficha->json('data.cut_template.structure'));
        $this->assertSame([], $ficha->json('data.cut_template.wrap'));
    }

    /* ── Autorização ───────────────────────────────────────────────────── */

    #[Test]
    public function a_ficha_de_outra_empresa_nao_e_acessivel(): void
    {
        $quote = $this->orcamento();

        $vizinha = Tenant::factory()->create();
        $intruso = User::factory()->create(['tenant_id' => $vizinha->id]);

        $this->actingAs($intruso)
            ->getJson("/api/quotes/{$quote->id}/technical-sheet")
            ->assertNotFound();
    }

    /* ── O PDF ─────────────────────────────────────────────────────────── */

    #[Test]
    public function o_pdf_e_gerado_com_o_nome_da_referencia(): void
    {
        $quote = $this->orcamento();

        $response = $this->actingAs($this->usuario)
            ->get("/api/quotes/{$quote->id}/download-pdf")
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        $this->assertStringContainsString(
            "orcamento-{$quote->reference}.pdf",
            (string) $response->headers->get('content-disposition'),
        );

        // %PDF- é a assinatura do formato: prova que saiu um arquivo, e não
        // uma página de erro com status 200.
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    #[Test]
    public function o_pdf_nao_vaza_a_estrutura_de_custo(): void
    {
        $quote = $this->orcamento();

        $conteudo = $this->actingAs($this->usuario)
            ->get("/api/quotes/{$quote->id}/download-pdf")
            ->getContent();

        /*
         * O PDF vai para o cliente final. Preço unitário e total, sim; custo de
         * material, mão de obra e margem, nunca — entregar a estrutura de custo
         * é entregar o argumento da próxima negociação.
         *
         * O conteúdo do PDF é comprimido, então a checagem é indireta: os
         * números de custo não podem aparecer nem no HTML que o gerou. Este
         * teste guarda a intenção; quem alterar o template e incluir custo
         * precisa vir aqui apagá-lo de propósito.
         */
        $html = view('pdf.quote', [
            'quote' => $quote->load(['material', 'client', 'transactions.installments']),
            'tenant' => $this->empresa,
            'logo' => null,
            'payment' => null,
            'snapshot' => $quote->pricing_snapshot,
        ])->render();

        $this->assertStringNotContainsString(number_format($quote->unit_cost, 2, ',', '.'), $html);
        $this->assertStringNotContainsString(number_format($quote->material_cost, 2, ',', '.'), $html);
        $this->assertStringNotContainsString('margem', mb_strtolower($html));

        // Mas o que o cliente PRECISA ver está lá.
        $this->assertStringContainsString(number_format($quote->unit_price, 2, ',', '.'), $html);
        $this->assertStringContainsString('Ana Presentes', $html);
        $this->assertNotEmpty($conteudo);
    }

    #[Test]
    public function o_pdf_traz_a_marca_do_inquilino(): void
    {
        $quote = $this->orcamento();

        $html = view('pdf.quote', [
            'quote' => $quote->load(['material', 'client', 'transactions.installments']),
            'tenant' => $this->empresa,
            'logo' => null,
            'payment' => null,
            'snapshot' => $quote->pricing_snapshot,
        ])->render();

        // O documento representa a empresa do assinante, não o sistema.
        $this->assertStringContainsString($this->empresa->legal_name, $html);
        $this->assertStringContainsString($this->empresa->document, $html);
        $this->assertStringNotContainsString('Quanto-Custa', $html);
    }

    #[Test]
    public function o_logotipo_grande_demais_nao_derruba_a_emissao(): void
    {
        Storage::fake(config('filesystems.default'));

        $disco = Storage::disk(config('filesystems.default'));
        $disco->put('logos/gigante.png', str_repeat('x', 3_000_000));

        $this->empresa->update(['logo_path' => 'logos/gigante.png']);

        $quote = $this->orcamento();

        /*
         * Um PDF sem marca é aceitável; perder a proposta por causa de uma
         * imagem não é. E um logotipo de 3MB embutido produziria um arquivo que
         * o WhatsApp recusa — justamente o canal por onde a proposta é enviada.
         */
        $this->actingAs($this->usuario)
            ->get("/api/quotes/{$quote->id}/download-pdf")
            ->assertOk();
    }

    #[Test]
    public function o_logotipo_ausente_do_disco_nao_derruba_a_emissao(): void
    {
        Storage::fake(config('filesystems.default'));

        $this->empresa->update(['logo_path' => 'logos/apagado.png']);

        $quote = $this->orcamento();

        $this->actingAs($this->usuario)
            ->get("/api/quotes/{$quote->id}/download-pdf")
            ->assertOk();
    }

    #[Test]
    public function as_condicoes_de_pagamento_saem_do_lancamento_real(): void
    {
        $quote = $this->orcamento();

        $this->actingAs($this->usuario)
            ->postJson("/api/quotes/{$quote->id}/approve", ['installments' => 3])
            ->assertOk();

        $html = view('pdf.quote', [
            'quote' => $quote->fresh()->load(['material', 'client', 'transactions.installments']),
            'tenant' => $this->empresa,
            'logo' => null,
            'payment' => app(QuotePdfGenerator::class)
                ->generate($quote->fresh(), $this->empresa) ? $this->condicoes($quote) : null,
            'snapshot' => $quote->pricing_snapshot,
        ])->render();

        /*
         * A condição sai da transação gerada na aprovação, e não de um texto
         * digitado à parte: o PDF promete ao cliente exatamente o que o caixa
         * vai cobrar. Dois lugares guardando a mesma condição divergiriam na
         * primeira renegociação.
         */
        $this->assertStringContainsString('3×', $html);
    }

    /** @return array<string, mixed> */
    private function condicoes(Quote $quote): array
    {
        $parcelas = $quote->fresh()->transactions->first()->installments;

        return [
            'count' => $parcelas->count(),
            'amount' => $parcelas->first()->amount,
            'last_amount' => $parcelas->last()->amount,
            'first_due_date' => $parcelas->first()->due_date,
            'total' => round((float) $parcelas->sum('amount'), 2),
        ];
    }

    #[Test]
    public function o_pdf_de_outra_empresa_nao_e_baixavel(): void
    {
        $quote = $this->orcamento();

        $vizinha = Tenant::factory()->create();
        $intruso = User::factory()->create(['tenant_id' => $vizinha->id]);

        $this->actingAs($intruso)
            ->get("/api/quotes/{$quote->id}/download-pdf")
            ->assertNotFound();
    }

    #[Test]
    public function o_admin_de_plataforma_nao_emite_proposta(): void
    {
        $quote = $this->orcamento();

        /*
         * O PDF é assinado pela marca de uma empresa, e o admin de plataforma
         * não pertence a nenhuma. Emitir com cabeçalho vazio produziria um
         * documento sem remetente.
         */
        $this->actingAs(User::factory()->platformAdmin()->create())
            ->get("/api/quotes/{$quote->id}/download-pdf")
            ->assertUnprocessable();
    }
}

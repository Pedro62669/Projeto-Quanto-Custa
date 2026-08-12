<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BoxModel;
use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Enums\UserRole;
use App\Models\CostSetting;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cartonagem rígida: tampa solta telescópica e a lista de materiais.
 *
 * O que está sob teste não é a soma — é o que a soma DEIXA DE ESCONDER. Até
 * aqui o motor cobrava um material por peça, e revestimento custa quatro vezes
 * o papelão cinza que ele cobre. Cada teste persegue um jeito de o segundo
 * material sumir da conta.
 */
class RigidBoxPricingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $usuario;

    private Material $cinza;

    private Material $revestimento;

    private Material $ima;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->create();
        $this->usuario = User::factory()->create([
            'tenant_id' => $this->empresa->id,
            'role' => UserRole::Admin,
        ]);

        CostSetting::factory()->create(['tenant_id' => $this->empresa->id]);

        $this->cinza = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'name' => 'Papelão cinza 1,9mm',
            'cost_per_unit' => 5.00,
            'thickness_mm' => 1.9,
        ]);

        $this->revestimento = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'name' => 'Papel color plus',
            'type' => MaterialType::Paper,
            'cost_per_unit' => 22.50,
        ]);

        $this->ima = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'name' => 'Ímã neodímio 6x2mm',
            'type' => MaterialType::Hardware,
            'cost_unit' => MaterialUnit::Piece,
            'cost_per_unit' => 0.42,
            'grammage_kg_per_m2' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function spec(array $overrides = []): array
    {
        return [
            'material_id' => $this->cinza->id,
            'box_model' => 'rigid_telescopic',
            'width_mm' => 300, 'height_mm' => 200, 'depth_mm' => 150,
            'quantity' => 100,
            'waste_percent' => 10,
            'production_minutes_per_unit' => 12,
            'profit_margin_percent' => 30,
            'pricing_mode' => 'markup',
            ...$overrides,
        ];
    }

    /* ── As duas áreas ─────────────────────────────────────────────────── */

    #[Test]
    public function o_revestimento_consome_mais_chapa_que_a_estrutura(): void
    {
        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())
            ->assertOk();

        /*
         * A assinatura do modelo. O papel não para na quina: vira 15mm por
         * borda sobre o cinza. Se algum dia alguém reusar a área da estrutura
         * para o revestimento, estas duas ficam iguais e o teste cai.
         */
        $this->assertGreaterThan(
            $response->json('data.area_m2_per_unit'),
            $response->json('data.wrap_area_m2_per_unit'),
        );
    }

    #[Test]
    public function os_modelos_dobrados_nao_tem_area_de_revestimento(): void
    {
        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec(['box_model' => 'rsc']))
            ->assertOk();

        // Numa caixa dobrada o material é um só: o papel impresso, quando
        // existe, já está laminado na chapa que o blank mede.
        $this->assertSame(0, $response->json('data.wrap_area_m2_per_unit'));
        $this->assertSame(0, $response->json('data.wrap_cost'));
    }

    /* ── A lista de materiais ──────────────────────────────────────────── */

    #[Test]
    public function o_revestimento_da_lista_entra_no_custo(): void
    {
        $sem = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec())->assertOk();

        $com = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'components' => [
                    ['material_id' => $this->revestimento->id, 'role' => 'wrap'],
                ],
            ]))->assertOk();

        $this->assertSame(0, $sem->json('data.wrap_cost'));
        $this->assertGreaterThan(0, $com->json('data.wrap_cost'));

        // A estrutura não muda: são duas linhas independentes.
        $this->assertSame($sem->json('data.material_cost'), $com->json('data.material_cost'));

        $this->assertGreaterThan($sem->json('data.unit_price'), $com->json('data.unit_price'));
    }

    #[Test]
    public function os_imas_entram_por_peca_e_multiplicam_pela_quantidade(): void
    {
        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'components' => [
                    ['material_id' => $this->ima->id, 'role' => 'hardware', 'quantity' => 4],
                ],
            ]))->assertOk();

        // 4 × R$ 0,42 = R$ 1,68 por caixa. Sem área, sem gramatura, sem
        // desperdício percentual em cima: ímã não tem apara.
        $this->assertSame(1.68, $response->json('data.hardware_cost'));
    }

    #[Test]
    public function a_lista_soma_varias_linhas_de_ferragem(): void
    {
        $fita = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'name' => 'Fita de cetim 15mm',
            'type' => MaterialType::Hardware,
            'cost_unit' => MaterialUnit::Piece,
            'cost_per_unit' => 1.85,
            'grammage_kg_per_m2' => null,
        ]);

        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'components' => [
                    ['material_id' => $this->ima->id, 'role' => 'hardware', 'quantity' => 4],
                    ['material_id' => $fita->id, 'role' => 'hardware', 'quantity' => 1],
                ],
            ]))->assertOk();

        // 4 × 0,42 + 1 × 1,85 = 3,53.
        $this->assertSame(3.53, $response->json('data.hardware_cost'));
    }

    #[Test]
    public function ferragem_sem_quantidade_conta_uma_peca(): void
    {
        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'components' => [['material_id' => $this->ima->id, 'role' => 'hardware']],
            ]))->assertOk();

        $this->assertSame(0.42, $response->json('data.hardware_cost'));
    }

    #[Test]
    public function a_ferragem_vale_tambem_em_modelo_dobrado(): void
    {
        // Existe caixa dobrada com fecho: a ferragem não é privilégio da rígida.
        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'box_model' => 'rsc',
                'components' => [
                    ['material_id' => $this->ima->id, 'role' => 'hardware', 'quantity' => 2],
                ],
            ]))->assertOk();

        $this->assertSame(0.84, $response->json('data.hardware_cost'));
    }

    #[Test]
    public function o_revestimento_e_ignorado_em_modelo_dobrado(): void
    {
        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'box_model' => 'rsc',
                'components' => [
                    ['material_id' => $this->revestimento->id, 'role' => 'wrap'],
                ],
            ]))->assertOk();

        /*
         * Um RSC não é revestido. Cobrar o papel apareceria na ficha como uma
         * operação que ninguém vai executar — e o cliente pagaria por ela.
         */
        $this->assertSame(0, $response->json('data.wrap_cost'));
    }

    #[Test]
    public function a_estrutura_na_lista_e_ignorada_sem_erro(): void
    {
        $comLinha = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'components' => [
                    ['material_id' => $this->cinza->id, 'role' => 'structure'],
                    ['material_id' => $this->revestimento->id, 'role' => 'wrap'],
                ],
            ]))->assertOk();

        $semLinha = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'components' => [
                    ['material_id' => $this->revestimento->id, 'role' => 'wrap'],
                ],
            ]))->assertOk();

        /*
         * O frontend exibe a lista COMPLETA, com a estrutura dentro. Obrigá-lo
         * a filtrar essa linha antes de enviar seria uma pegadinha; contá-la
         * duas vezes seria pior.
         */
        $this->assertSame(
            $semLinha->json('data.unit_price'),
            $comLinha->json('data.unit_price'),
        );
    }

    /* ── As unidades ───────────────────────────────────────────────────── */

    #[Test]
    public function ima_usado_como_area_falha_de_forma_explicita(): void
    {
        /*
         * Um ímã de 6×2mm tem R$/m² astronômico e sem significado. Sem a
         * guarda, escolhê-lo como revestimento produziria um preço absurdo
         * que ninguém saberia explicar.
         */
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'components' => [['material_id' => $this->ima->id, 'role' => 'wrap']],
            ]))
            ->assertUnprocessable()
            /*
             * A mensagem NOMEIA a unidade cadastrada ("cotado em peça"), e não
             * uma genérica: dizer "é cotado por peça" para um bloco de espuma
             * mandava o usuário procurar um erro que não existia.
             */
            ->assertJsonPath(
                'errors.pricing.0',
                fn ($m) => str_contains($m, 'cotado em peça')
                    && str_contains($m, 'não tem custo por m²'),
            );
    }

    #[Test]
    public function papel_usado_como_ferragem_falha_de_forma_explicita(): void
    {
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'components' => [
                    ['material_id' => $this->revestimento->id, 'role' => 'hardware'],
                ],
            ]))
            ->assertUnprocessable();
    }

    /* ── Isolamento ────────────────────────────────────────────────────── */

    #[Test]
    public function nao_da_para_usar_material_de_outra_empresa_na_lista(): void
    {
        $vizinha = Tenant::factory()->create();
        $alheio = Material::factory()->create(['tenant_id' => $vizinha->id]);

        // A validação `exists` roda sem escopo de tenant, então quem barra é o
        // findOrFail escopado do controller — 404, não preço com custo alheio.
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'components' => [['material_id' => $alheio->id, 'role' => 'wrap']],
            ]))
            ->assertNotFound();
    }

    /* ── Caixa livro ───────────────────────────────────────────────────── */

    #[Test]
    public function a_caixa_livro_nao_expoe_campos_de_tampa(): void
    {
        /*
         * A capa é inteiriça e articulada na lombada: não existe peça de tampa
         * a dimensionar, e oferecer os campos sugeriria um grau de liberdade
         * que o modelo não tem.
         */
        $this->assertFalse(BoxModel::RigidBook->hasSeparateLid());
        $this->assertFalse(BoxModel::RigidBookFlap->hasSeparateLid());
    }

    #[Test]
    public function a_aba_de_fechamento_encarece_cinza_e_papel(): void
    {
        $semAba = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'box_model' => 'rigid_book',
                'components' => [['material_id' => $this->revestimento->id, 'role' => 'wrap']],
            ]))->assertOk();

        $comAba = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'box_model' => 'rigid_book_flap',
                'components' => [['material_id' => $this->revestimento->id, 'role' => 'wrap']],
            ]))->assertOk();

        $this->assertGreaterThan(
            $semAba->json('data.area_m2_per_unit'),
            $comAba->json('data.area_m2_per_unit'),
        );

        $this->assertGreaterThan(
            $semAba->json('data.wrap_area_m2_per_unit'),
            $comAba->json('data.wrap_area_m2_per_unit'),
        );
    }

    #[Test]
    public function a_canaleta_entra_no_papel_e_nao_no_papelao(): void
    {
        /*
         * O teste central do modelo. A aba acrescenta UM painel de cinza e UM
         * painel MAIS UMA canaleta de papel. Então o papel tem que subir
         * proporcionalmente MAIS que o cinza.
         *
         * Se alguém somar a canaleta também no cinza, os dois sobem na mesma
         * proporção e este teste cai — que é exatamente o erro que ele existe
         * para pegar, porque cobraria do cliente o ar da dobradiça.
         */
        $semAba = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec(['box_model' => 'rigid_book']))
            ->assertOk();

        $comAba = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec(['box_model' => 'rigid_book_flap']))
            ->assertOk();

        $crescimentoCinza = $comAba->json('data.area_m2_per_unit')
            / $semAba->json('data.area_m2_per_unit');

        $crescimentoPapel = $comAba->json('data.wrap_area_m2_per_unit')
            / $semAba->json('data.wrap_area_m2_per_unit');

        $this->assertGreaterThan($crescimentoCinza, $crescimentoPapel);
    }

    #[Test]
    public function a_caixa_livro_consome_mais_papel_que_papelao(): void
    {
        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec(['box_model' => 'rigid_book']))
            ->assertOk();

        // A assinatura de toda cartonagem rígida: o papel vira sobre as bordas.
        $this->assertGreaterThan(
            $response->json('data.area_m2_per_unit'),
            $response->json('data.wrap_area_m2_per_unit'),
        );
    }

    #[Test]
    public function espessura_zero_nao_degenera_o_modelo(): void
    {
        $semEspessura = Material::factory()->create([
            'tenant_id' => $this->empresa->id,
            'cost_per_unit' => 5.00,
            'thickness_mm' => 0.0,
        ]);

        // Sem espessura a canaleta vira zero. O modelo precisa continuar
        // produzindo área positiva em vez de degenerar.
        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'box_model' => 'rigid_book_flap',
                'material_id' => $semEspessura->id,
            ]))->assertOk();

        $this->assertGreaterThan(0, $response->json('data.area_m2_per_unit'));
        $this->assertGreaterThan(0, $response->json('data.wrap_area_m2_per_unit'));
    }

    /* ── Caixa ímã ─────────────────────────────────────────────────────── */

    /** @return array<string, float> área do cinza e do papel do modelo */
    private function areas(string $model): array
    {
        $r = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec(['box_model' => $model]))
            ->assertOk();

        return [
            'cinza' => (float) $r->json('data.area_m2_per_unit'),
            'papel' => (float) $r->json('data.wrap_area_m2_per_unit'),
        ];
    }

    #[Test]
    public function as_tres_variacoes_de_ima_consomem_areas_diferentes(): void
    {
        $simples = $this->areas('rigid_magnet');
        $laterais = $this->areas('rigid_magnet_side');
        $envolvente = $this->areas('rigid_magnet_wrap');

        /*
         * O teste que justifica existirem três modelos em vez de um com um
         * contador de ímãs. Se as variações fossem só a quantidade de ferragem,
         * as três teriam o mesmo blank — e o cliente pagaria o mesmo por uma
         * caixa que consome mais capa.
         */
        $this->assertNotEqualsWithDelta($simples['cinza'], $laterais['cinza'], 0.0001);
        $this->assertNotEqualsWithDelta($simples['cinza'], $envolvente['cinza'], 0.0001);
        $this->assertNotEqualsWithDelta($laterais['cinza'], $envolvente['cinza'], 0.0001);
    }

    #[Test]
    public function a_aba_envolvente_consome_mais_capa_que_a_simples(): void
    {
        $simples = $this->areas('rigid_magnet');
        $envolvente = $this->areas('rigid_magnet_wrap');

        // Ela desce a parede frontal E dobra sob o fundo: mais capa corrida.
        $this->assertGreaterThan($simples['cinza'], $envolvente['cinza']);
        $this->assertGreaterThan($simples['papel'], $envolvente['papel']);
    }

    #[Test]
    public function as_abas_laterais_acrescentam_paineis_proprios(): void
    {
        $simples = $this->areas('rigid_magnet');
        $laterais = $this->areas('rigid_magnet_side');

        /*
         * As laterais não esticam a capa corrida — elas correm ao longo da
         * profundidade, como painéis à parte. E no revestimento pesam mais que
         * proporcionalmente: cada uma leva virada nas QUATRO bordas, porque
         * fica exposta pelos dois lados ao abrir a caixa.
         */
        $this->assertGreaterThan($simples['cinza'], $laterais['cinza']);

        $crescimentoCinza = $laterais['cinza'] / $simples['cinza'];
        $crescimentoPapel = $laterais['papel'] / $simples['papel'];

        $this->assertGreaterThan($crescimentoCinza, $crescimentoPapel);
    }

    #[Test]
    public function o_ima_consome_mais_que_a_caixa_livro_equivalente(): void
    {
        $livro = $this->areas('rigid_book');
        $ima = $this->areas('rigid_magnet');

        // A aba do fecho é um painel inteiro de cinza — precisa de corpo para
        // alojar o ímã sem estufar a superfície.
        $this->assertGreaterThan($livro['cinza'], $ima['cinza']);
    }

    #[Test]
    public function a_familia_ima_sugere_imas_sem_cobrar_por_eles(): void
    {
        $this->assertSame(2, BoxModel::RigidMagnet->suggestedMagnets());
        $this->assertSame(4, BoxModel::RigidMagnetSide->suggestedMagnets());
        $this->assertSame(0, BoxModel::RigidBook->suggestedMagnets());

        /*
         * A sugestão NÃO entra no preço: quem cobra é a lista de materiais.
         * Um modelo que cobrasse ímã sozinho cobraria duas vezes de quem
         * lançasse a ferragem corretamente.
         */
        $semFerragem = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec(['box_model' => 'rigid_magnet']))
            ->assertOk();

        $this->assertSame(0, $semFerragem->json('data.hardware_cost'));
    }

    #[Test]
    public function o_ima_e_cartonagem_rigida_e_aceita_revestimento(): void
    {
        $response = $this->actingAs($this->usuario)
            ->postJson('/api/quotes/simulate', $this->spec([
                'box_model' => 'rigid_magnet_side',
                'components' => [
                    ['material_id' => $this->revestimento->id, 'role' => 'wrap'],
                    ['material_id' => $this->ima->id, 'role' => 'hardware', 'quantity' => 4],
                ],
            ]))->assertOk();

        $this->assertGreaterThan(0, $response->json('data.wrap_cost'));
        $this->assertSame(1.68, $response->json('data.hardware_cost'));
    }

    /* ── Persistência ──────────────────────────────────────────────────── */

    #[Test]
    public function o_orcamento_gravado_guarda_os_dois_custos(): void
    {
        $this->actingAs($this->usuario)
            ->postJson('/api/quotes', $this->spec([
                'client_name' => 'Cliente rígido',
                'components' => [
                    ['material_id' => $this->revestimento->id, 'role' => 'wrap'],
                    ['material_id' => $this->ima->id, 'role' => 'hardware', 'quantity' => 4],
                ],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.costs.hardware', 1.68);

        $this->assertDatabaseCount('quotes', 1);
    }
}

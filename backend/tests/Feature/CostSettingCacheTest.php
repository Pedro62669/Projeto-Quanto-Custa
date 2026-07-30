<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CostSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regressão do cache da configuração de custos.
 *
 * Contexto: `CostSetting::current()` cacheava o model Eloquent. Funcionava na
 * suíte (que usa o driver `array`, o qual NÃO serializa) e quebrava em
 * produção, onde o driver serializa — o Laravel 13 desserializa o cache com
 * uma allowlist de classes vazia por padrão, então o model voltava como
 * __PHP_Incomplete_Class e o cálculo estourava em runtime.
 *
 * Estes testes rodam contra um store que serializa de fato, para que a mesma
 * classe de bug não passe de novo por uma diferença entre teste e produção.
 */
class CostSettingCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ArrayStore com $serializesValues = true reproduz o comportamento dos
        // drivers reais (database, redis, file) sem exigir infra externa.
        config()->set('cache.default', 'array');
        config()->set('cache.stores.array.serialize', true);
        cache()->purge('array');
    }

    #[Test]
    public function a_configuracao_sobrevive_ao_ciclo_de_serializacao_do_cache(): void
    {
        CostSetting::factory()->create(['energy_tariff_per_kwh' => 0.92]);

        // Primeira leitura: popula o cache a partir do banco.
        $primeira = CostSetting::current();

        // Segunda leitura: vem do cache, passando por serialize/unserialize.
        $segunda = CostSetting::current();

        $this->assertInstanceOf(CostSetting::class, $segunda);
        $this->assertSame($primeira->id, $segunda->id);
        $this->assertSame(0.92, $segunda->energy_tariff_per_kwh);

        // Os casts precisam continuar valendo depois da reidratação — sem isso
        // o motor receberia strings onde espera float.
        $this->assertIsFloat($segunda->machine_hour_rate);
        $this->assertIsFloat($segunda->labor_hour_rate);
    }

    #[Test]
    public function publicar_uma_nova_versao_invalida_o_cache(): void
    {
        CostSetting::factory()->create([
            'energy_tariff_per_kwh' => 0.92,
            'effective_from' => now()->subDay(),
        ]);

        $this->assertSame(0.92, CostSetting::current()->energy_tariff_per_kwh);

        // Reajuste: o observer `saved` precisa limpar o cache, senão a API
        // continuaria orçando com a tarifa antiga até o cache expirar (e ele
        // é rememberForever — ou seja, para sempre).
        CostSetting::factory()->create([
            'energy_tariff_per_kwh' => 1.05,
            'effective_from' => now(),
        ]);

        $this->assertSame(1.05, CostSetting::current()->energy_tariff_per_kwh);
    }

    #[Test]
    public function sem_configuracao_vigente_a_falha_e_explicita(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Cadastre os custos fixos/');

        CostSetting::current();
    }
}

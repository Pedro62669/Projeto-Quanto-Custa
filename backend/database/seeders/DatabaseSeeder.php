<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Ponto de entrada de `db:seed` e de `migrate:fresh --seed`.
 *
 * Duas correções em relação ao esqueleto do Laravel, e as duas importam desde
 * que o sistema virou multi-inquilino:
 *
 * 1. O InitialDataSeeder passa a ser chamado de fato. Antes ele existia e
 *    ninguém o invocava — quem instalasse o projeto do zero terminava sem
 *    administrador, sem custos e sem material nenhum, e a calculadora estourava
 *    DomainException no primeiro cálculo. O login documentado no README só
 *    funcionava para quem rodasse `db:seed --class=InitialDataSeeder` à mão.
 *
 * 2. O `User::factory()` de exemplo saiu. Ele parecia inofensivo, mas a
 *    UserFactory resolve a empresa por TenantFactory::daSuite(), que CRIA uma
 *    empresa quando não existe nenhuma — com nome vindo do Faker. Toda
 *    instalação nova nascia com uma companhia fantasma ocupando o id 1, e era
 *    ela que o TenantScope enxergava primeiro.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(InitialDataSeeder::class);
    }
}

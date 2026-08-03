# quantoCusta

Sistema SaaS de orçamento e precificação de embalagens sob medida.

Arquitetura headless: **Laravel 13 (API REST)** no backend, **Next.js 16 +
React 19 + Tailwind 4 + shadcn/ui + React Three Fiber** no frontend.

Estado: **funcionando de ponta a ponta**, com login, cálculo reativo,
visualização 3D e gravação de orçamentos verificados em navegador real.

---

## Estrutura

```
backend/
  app/
    Enums/                     UserRole, MaterialType, MaterialUnit, BoxModel
    Models/                    User, Material, CostSetting, Quote
    Services/Pricing/          ← toda a regra de negócio
      BlankCalculator.php        geometria: dimensões → área de material
      PricingEngine.php          dinheiro: área + tempo → custo → preço
      PricingInput/Result.php    DTOs de entrada e saída
    Console/Commands/
      ExportPricingFixtures.php  gera os casos do teste de paridade
    Http/
      Controllers/Api/         Auth, Quote, Material
      Controllers/Api/Admin/   Material, CostSetting, User
      Requests/ Resources/ Middleware/
    Policies/QuotePolicy.php
  config/cors.php              origens permitidas via FRONTEND_URL
  database/migrations/ seeders/ factories/
  tests/                       77 testes

frontend/
  lib/pricing/
    types.ts                   contrato compartilhado com a API
    engine.ts                  ← espelho TS do PricingEngine (preview local)
    __fixtures__/parity.json   gerado pelo PHP
  lib/api.ts  lib/auth.ts
  store/useQuoteStore.ts       estado (Zustand)
  hooks/                       useServerSync, useSession
  components/three/
    BoxMesh.tsx                a malha (isolada, sem Canvas)
    BoxViewer.tsx              o palco (Canvas, luzes, OrbitControls)
  components/calculator/       DimensionForm, PriceSummary, SaveQuoteDialog
  app/                         /login, /calculadora
  scripts/parity-check.ts      PHP ↔ TS, tolerância zero
  e2e/smoke.mjs                navegador real (Playwright)
```

---

## Decisões de arquitetura

**O motor de cálculo existe duas vezes, de propósito.** `PricingEngine.php` e
`engine.ts` implementam a mesma fórmula com os mesmos nomes de campo. O TS dá
resposta instantânea enquanto o usuário digita; o PHP é a autoridade e
recalcula tudo ao salvar. O payload de gravação **não contém valores** — só a
especificação —, então não há caminho pelo qual o navegador defina o preço
(coberto por teste: `o_preco_enviado_pelo_cliente_e_ignorado`).

O risco da duplicação é os dois divergirem em silêncio. Fechado por
`npm run test:parity`: 52 cenários gerados pelo PHP, 988 campos comparados com
**tolerância zero**. Alterou uma fórmula? Rode
`php artisan pricing:export-fixtures` e o teste acusa qualquer divergência.

**Área ≠ soma das faces.** Uma caixa é uma chapa única dobrada, com abas de
colagem e de fechamento que se sobrepõem. `BlankCalculator` usa a planificação
real de cada modelo. Somar as 6 faces subestima o consumo num RSC em 15–30% —
o suficiente para o orçamento sair no prejuízo.

**Sete modelos, sete planificações.** Caixa americana (RSC), caixa com tampa,
luva, saco, tubo cilíndrico, caixa gaveta e mailer box — cada um com sua
fórmula de blank em `BlankCalculator`. O tubo reaproveita `width_mm` como
diâmetro e ignora a profundidade: num cilindro as duas SÃO a mesma medida,
então a caixa envolvente continua verdadeira e nenhuma coluna nova é
necessária. Seu corpo é planificado pela circunferência da linha média
(π×(D+espessura)), porque enrolar uma chapa faz a face externa percorrer
caminho maior que a interna.

A **gaveta** são duas peças: a luva envolve a gaveta JÁ MONTADA, vencendo as
paredes dela e a folga de deslize — dimensioná-la pela caixa "por fora"
produziria uma gaveta que não entra na própria luva. A **mailer box** (RETT) é
peça única die-cut com tampa articulada, e sua lateral custa DOBRADO: a aba
presa ao fundo sobe, dobra 180° no topo e desce por dentro. É por isso que ela
consome mais chapa que um RSC do mesmo tamanho, e a diferença é real.

Cuidado com invariantes fáceis: "duas peças custam mais que uma" é **falso**.
Numa caixa larga e rasa as abas do RSC valem meia profundidade cada, dominam o
blank e o RSC passa a gastar mais que a gaveta. O contra-exemplo está fixado
como teste (`numa_caixa_larga_e_rasa_o_rsc_pode_consumir_mais`) para que
ninguém "conserte" uma fórmula que está correta.

**Tampa: sugerida, não imposta.** Bandeja e tubo têm tampa como peça separada.
As medidas nascem derivadas da base e podem ser digitadas eixo a eixo — fixar
só a altura deixa o resto acompanhando a caixa. O `null` significa "automático",
e é persistido como tal para que reabrir o orçamento reproduza a mesma peça. A
tampa informada entra no **plano de corte**, não só no desenho: caso contrário
uma tampa mais alta sairia de graça.

**Orçamento é documento, não consulta.** Os valores são colunas materializadas
e `pricing_snapshot` guarda os parâmetros vigentes na emissão. Reajustar o
papelão amanhã não reescreve o que o cliente recebeu hoje.

**Markup vs. margem — e o imposto.** `markup` (padrão) faz `custo × (1 + m)`:
30% de markup entrega 23,1% de margem real. `margin` faz
`custo ÷ (1 − m − impostos)` — o markup divisor clássico, com margem e tributo
saindo do **mesmo** divisor. Aplicá-los em sequência derrubaria a margem de
30% para 27,6%, quebrando a promessa que a interface faz. O painel sempre
mostra a margem efetiva.

**Custos fixos são versionados, não editáveis.** Reajustar cria uma nova linha
com `effective_from`; a vigente é a mais recente já iniciada. Editar a linha
atual reescreveria a base de cálculo de orçamentos já emitidos.

---

## Como rodar

A raiz do repositório **não é uma aplicação** — é o guarda-chuva dos dois
projetos, com um `package.json` que serve só de atalho:

```bash
npm run dev          # sobe API (:8000) e frontend (:3000) juntos
npm test             # 77 testes PHP + tipos, lint, paridade e build do front
npm run test:e2e     # navegador real (exige os servidores no ar)
                     # credenciais: E2E_EMAIL / E2E_PASSWORD
npm run lint         # Pint
npm run fixtures     # regenera os casos de paridade após mudar uma fórmula
```

Rodar cada lado isoladamente também funciona: `cd backend && php artisan serve`
e `cd frontend && npm run dev`.

> O `npm run dev` roda um preflight (`scripts/preflight.mjs`) que checa as
> portas 3000 e 8000 antes de subir. Como o runner usa `--kill-others`, uma
> porta ocupada derrubaria os dois servidores com uma mensagem que não diz o
> que fazer — o preflight nomeia o processo e entrega o `kill` pronto.

> `frontend/next.config.ts` fixa `turbopack.root`. Isso **não é opcional**: o
> Turbopack infere a raiz pelo lockfile mais acima e, com o package.json da
> raiz presente, passaria a resolver módulos contra o `node_modules` errado —
> derrubando a aplicação com `MODULE_NOT_FOUND`.

---

## Instalação

Requisitos: PHP 8.4+, Composer, Node 20+, PostgreSQL (ou MySQL 8+).

### Backend

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
```

`.env`:

```
DB_CONNECTION=pgsql
DB_DATABASE=quantocusta
DB_USERNAME=...
DB_PASSWORD=...
FRONTEND_URL=http://localhost:3000
```

```bash
php artisan migrate
php artisan db:seed --class=InitialDataSeeder
php artisan serve
```

> A seed de `cost_settings` é **obrigatória**: sem uma configuração vigente,
> `CostSetting::current()` lança exceção e nenhum orçamento é calculado.
>
> Credenciais iniciais: `admin@quantocusta.local` / `admin123`, configuráveis
> por `ADMIN_EMAIL` e `ADMIN_PASSWORD` no `.env`. **Defina-as antes de semear
> em qualquer ambiente que não seja o seu.**

### Frontend

```bash
cd frontend
npm install
echo 'NEXT_PUBLIC_API_URL=http://localhost:8000/api' > .env.local
npm run dev     # → http://localhost:3000
```

---

## Verificação

```bash
# Backend — 77 testes
cd backend
php artisan test
./vendor/bin/pint --test app/ database/ routes/ tests/ config/

# Frontend — tipos, lint, paridade PHP↔TS e build
cd frontend
npm run verify

# Navegador real (exige os dois servidores no ar)
npm run test:e2e
```

O que a suíte cobre além do caminho feliz: adulteração de preço no payload,
IDOR entre usuários, barreira de admin, enumeração de usuários no login,
throttle de força bruta, revogação de token por dispositivo, imutabilidade do
orçamento emitido e o ciclo de serialização do cache.

---

## Endpoints

| Método | Rota | Acesso | Descrição |
|---|---|---|---|
| `POST` | `/api/login` | público | Token Bearer (7 dias), throttle por e-mail+IP |
| `POST` | `/api/logout` | autenticado | Revoga apenas o token atual |
| `GET` | `/api/materials` | usuário | Materiais ativos (sem preço de compra) |
| `GET` | `/api/pricing/parameters` | usuário | Defaults do formulário |
| `POST` | `/api/quotes/simulate` | usuário | Cálculo sem persistir |
| `GET` | `/api/quotes` | usuário | Histórico (escopado por usuário) |
| `POST` | `/api/quotes` | usuário | Salva — **recalcula no servidor** |
| `GET/PATCH/DELETE` | `/api/quotes/{id}` | dono/admin | Protegido por `QuotePolicy` |
| `GET/POST/PUT/DELETE` | `/api/admin/materials` | admin | CRUD de matérias-primas |
| `GET/POST` | `/api/admin/cost-settings` | admin | Custos fixos (versionados) |
| `GET/POST/PUT/DELETE` | `/api/admin/users` | admin | Gestão de usuários |

---

## Pendências conhecidas

1. **Sem tela de admin.** A API administrativa está completa e testada, mas o
   cadastro de materiais e custos hoje só é acessível via HTTP direto.
2. **Token em localStorage.** Coerente com a arquitetura headless, mas legível
   por XSS. Mitigado por expiração de 7 dias e revogação no logout; para dados
   mais sensíveis, migrar para cookie httpOnly + Sanctum stateful (exige
   frontend e API sob o mesmo domínio).
3. **`npm audit` acusa 9 issues** na cadeia do ESLint. São dev-only e a
   correção quebra o lint — ver a nota `//overrides` no `package.json`.
   **Nunca rode `npm audit fix --force`**: ele rebaixa o Next 16 para 9.3.3.
4. **Multi-tenant.** Custos fixos e materiais são globais; um SaaS real precisa
   de `tenant_id` em `materials`, `cost_settings` e `quotes`.
5. **PDF do orçamento** a partir do `pricing_snapshot`.

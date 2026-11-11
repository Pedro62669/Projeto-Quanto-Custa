<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Plano contratado pela empresa assinante.
 *
 * Os limites moram AQUI, no enum, e não numa tabela `plans`. A razão é o que
 * acontece quando o plano muda: se cada empresa carregasse uma cópia dos
 * números, subir o teto do Pro exigiria um UPDATE em massa, e quem entrasse
 * amanhã pegaria valores diferentes de quem entrou ontem — sem que nada no
 * código denunciasse a divergência.
 *
 * As colunas `max_*` em `tenants` continuam existindo, mas são NULLABLE e
 * significam "cortesia": só quem recebeu uma exceção manual tem número próprio.
 * Todo o resto segue o plano e acompanha qualquer mudança dele de graça.
 * Ver Tenant::limiteDe().
 */
enum PlanType: string
{
    /** O plano da cartonagem que trabalha sozinha ou com um ajudante. */
    case Basic = 'basic';

    /** Sem tetos. É o argumento de venda do plano — não invente um limite. */
    case Pro = 'pro';

    public function label(): string
    {
        return match ($this) {
            self::Basic => 'Básico',
            self::Pro => 'Profissional',
        };
    }

    /** Mensalidade em reais. Todo plano é pago — não existe plano de R$ 0,00. */
    public function monthlyPrice(): float
    {
        return match ($this) {
            self::Basic => 79.90,
            self::Pro => 149.90,
        };
    }

    /**
     * Preço cheio, para quando a mensalidade praticada é de LANÇAMENTO.
     *
     * NULL significa "o preço praticado já é o cheio" — não há nada a riscar. É
     * o padrão, e é para onde o Profissional volta no dia em que a mensalidade
     * subir: apaga-se este valor e a tabela deixa de mostrar o riscado sozinha,
     * sem ninguém precisar caçar um número escrito na interface.
     *
     * ## Por que mora aqui, e não na tela
     *
     * Pela mesma razão que `monthlyPrice()` mora aqui, e a razão tem história:
     * a tela de assinatura já mostrou R$ 99,90 num cartão enquanto o cabeçalho
     * da MESMA página dizia R$ 149,90, porque a interface mantinha a própria
     * lista. Um preço riscado escrito à mão repete o erro no lugar em que ele é
     * mais caro — o número que o visitante usa para decidir que está fazendo
     * negócio.
     *
     * ## Por que ele PRECISA ser um compromisso, e não um enfeite
     *
     * Preço riscado tem regra: o CDC (art. 37, §1º) e o Decreto 5.903/2006
     * exigem que o valor anunciado como anterior tenha sido efetivamente
     * praticado, ou — no caso de lançamento — que a subida seja real e
     * informada. Um "de/por" com um número que ninguém nunca cobrou é
     * publicidade enganosa, e quem responde é a empresa anunciante.
     *
     * Este valor está aqui porque existe a decisão de subir o Profissional para
     * ele. No dia em que subir, `monthlyPrice()` passa a devolver 224.85 e este
     * método volta a NULL. Se a decisão mudar e a subida não acontecer, o certo
     * é apagar daqui — e não deixar o riscado no ar.
     */
    public function fullPrice(): ?float
    {
        return match ($this) {
            self::Basic => null,
            self::Pro => 224.85,
        };
    }

    /**
     * Teto de matérias-primas cadastradas. Null = ilimitado.
     *
     * Contagem ABSOLUTA: material é cadastro, não movimento. Quem tem 40 papéis
     * no estoque tem 40 o ano inteiro.
     */
    public function maxMaterials(): ?int
    {
        return match ($this) {
            self::Basic => 60,
            self::Pro => null,
        };
    }

    /**
     * Teto de clientes cadastrados. Null = ilimitado. Também absoluto.
     */
    public function maxClients(): ?int
    {
        return match ($this) {
            self::Basic => 300,
            self::Pro => null,
        };
    }

    /**
     * Teto de orçamentos NO MÊS. Null = ilimitado.
     *
     * Por mês, e não no total — a diferença é o que separa uma cota de um
     * tijolo. Orçamento não é só rascunho: é o lastro da venda aprovada, com a
     * transação do caixa apontando para ele e a ficha técnica saindo dele. Um
     * teto acumulado significaria que a empresa, ao completar um ano de uso,
     * simplesmente para de conseguir vender — e o caminho para destravar seria
     * apagar o próprio histórico financeiro.
     *
     * A janela mensal ainda limita o crescimento da base (que é o custo real de
     * banco que a cota existe para conter) sem nunca bloquear quem já vendeu.
     */
    public function maxQuotesPerMonth(): ?int
    {
        return match ($this) {
            self::Basic => 250,
            self::Pro => null,
        };
    }

    /**
     * O plano como a API o descreve.
     *
     * Existe porque DUAS telas mostram preço: a página pública, para quem ainda
     * está decidindo, e a de assinatura, para quem já é cliente. Descrever o
     * plano em cada uma delas é escrever a mensalidade duas vezes — e, no dia do
     * reajuste, a que fica desatualizada é sempre a que o visitante lê antes de
     * assinar. O compromisso errado é o que gera reclamação no Procon.
     *
     * @return array{tipo: string, rotulo: string, mensalidade: float, mensalidade_cheia: float|null, limites: array{materiais: int|null, clientes: int|null, orcamentos_por_mes: int|null}}
     */
    public function toArray(): array
    {
        return [
            'tipo' => $this->value,
            'rotulo' => $this->label(),
            'mensalidade' => $this->monthlyPrice(),

            // Null = sem preço de lançamento; a tela não risca nada.
            'mensalidade_cheia' => $this->fullPrice(),

            // Null = ilimitado, o mesmo contrato do resumo de cotas.
            'limites' => [
                'materiais' => $this->maxMaterials(),
                'clientes' => $this->maxClients(),
                'orcamentos_por_mes' => $this->maxQuotesPerMonth(),
            ],
        ];
    }

    /**
     * A tabela de preços inteira, na ordem em que os planos foram declarados —
     * do mais barato ao mais completo, que é como se lê uma tabela de preços.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalogo(): array
    {
        return array_map(static fn (self $plano): array => $plano->toArray(), self::cases());
    }
}

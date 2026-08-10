<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\BoxModel;
use App\Services\Pricing\BlankCalculator;

/**
 * Gabarito de corte: as peças que o operador recorta no chão de fábrica.
 *
 * ⚠️  DERIVA DO MOTOR DE PREÇO, e isso não é preferência de arquitetura — é a
 * única forma de o documento não mentir.
 *
 * Uma fórmula independente aqui produziria o pior defeito que este sistema
 * pode ter: o cliente paga por uma caixa e a produção corta outra. O erro não
 * apareceria em teste nenhum (a paridade compara PHP com TS, nunca o preço com
 * a ficha) e só seria descoberto na bancada, com o papelão já cortado.
 *
 * Por isso as medidas saem de `bookLayout()` e das mesmas convenções do
 * BlankCalculator. As dimensões informadas pelo usuário são as INTERNAS da
 * caixa — o vão útil — e todo o resto é derivado delas.
 */
final class CutTemplateCalculator
{
    /**
     * Vira do revestimento, em mm.
     *
     * O mesmo valor que o BlankCalculator cobra no preço. Duplicá-lo com outro
     * número faria a folha cortada não bater com a folha paga.
     */
    private const TURN_IN_MM = 15.0;

    /**
     * Folga do revestimento INTERNO do fundo, em mm.
     *
     * O forro interno assenta dentro do vão já revestido pelas viradas, e por
     * isso é cortado 2mm menor: na medida exata ele encavala nas viradas e
     * estufa o fundo.
     */
    private const INNER_LINING_CLEARANCE_MM = 2.0;

    /**
     * As peças a cortar, agrupadas por material.
     *
     * @return array{
     *     structure: list<array{name: string, width_mm: float, height_mm: float, quantity: int}>,
     *     wrap: list<array{name: string, width_mm: float, height_mm: float, quantity: int}>,
     *     notes: list<string>
     * }
     */
    public function forQuote(
        BoxModel $model,
        float $widthMm,
        float $heightMm,
        float $depthMm,
        float $thicknessMm,
        ?array $lidMm = null,
        array $customParts = [],
    ): array {
        if ($model->isFree()) {
            return $this->freeTemplate($customParts);
        }

        $calculator = new BlankCalculator(thicknessMm: $thicknessMm);

        if ($model->isBook()) {
            return $this->bookTemplate($calculator, $model, $widthMm, $heightMm, $depthMm, $thicknessMm);
        }

        if ($model === BoxModel::RigidTelescopic) {
            return $this->telescopicTemplate(
                $calculator, $model, $widthMm, $heightMm, $depthMm, $thicknessMm, $lidMm,
            );
        }

        return $this->foldedTemplate($calculator, $model, $widthMm, $heightMm, $depthMm, $lidMm);
    }

    /**
     * Caixa tampa solta rígida: base e tampa, cada uma em cinco painéis.
     *
     * O FUNDO tem exatamente as medidas internas — é ele que define o vão útil.
     * As paredes frente/trás têm a largura do fundo; as laterais são mais
     * longas, porque envolvem a espessura das outras duas. Trocar isso faz a
     * caixa fechar torta, e é o erro mais comum de quem monta pela primeira vez.
     */
    private function telescopicTemplate(
        BlankCalculator $calculator,
        BoxModel $model,
        float $w,
        float $h,
        float $d,
        float $t,
        ?array $lidMm,
    ): array {
        $lid = $lidMm ?? $calculator->defaultLidDimensions($model, $w, $h, $d);

        $pecas = [
            ...$this->rigidBoxPanels('Base', $w, $h, $d, $t),
            ...$this->rigidBoxPanels('Tampa', $lid['width'], $lid['height'], $lid['depth'], $t),
        ];

        return [
            'structure' => $pecas,
            'wrap' => [
                $this->wrapSheet('Revestimento externo da base', $w, $h, $d, $t),
                $this->wrapSheet('Revestimento externo da tampa', $lid['width'], $lid['height'], $lid['depth'], $t),
                $this->innerLining('Forro interno do fundo da base', $w, $d, $t),
                $this->innerLining('Forro interno do fundo da tampa', $lid['width'], $lid['depth'], $t),
            ],
            'notes' => [
                'As medidas do fundo são as INTERNAS da caixa — o vão útil precifiado.',
                'As paredes laterais são mais longas: elas envolvem a espessura das paredes frente/trás.',
                self::AVISO_FORRO,
            ],
        ];
    }

    /**
     * Família da capa rígida (livro e ímã).
     *
     * A capa sai em painéis SEPARADOS, e a distância entre eles — a canaleta —
     * é montada na hora da colagem, não cortada. Por isso ela aparece nas
     * observações e não como peça.
     */
    private function bookTemplate(
        BlankCalculator $calculator,
        BoxModel $model,
        float $w,
        float $h,
        float $d,
        float $t,
    ): array {
        $l = $calculator->bookLayout($model, $w, $h, $d, $t);

        $capa = [
            $this->peca('Contracapa', $l['capaW'], $l['capaD']),
            $this->peca('Lombada', $l['lombada'], $l['capaD']),
            $this->peca('Tampa (capa)', $l['capaW'], $l['capaD']),
        ];

        if ($l['aba'] > 0) {
            $capa[] = $this->peca('Aba de fechamento', $l['aba'], $l['capaD']);
        }

        if ($l['magnetFlap'] > 0) {
            $capa[] = $this->peca('Aba do fecho magnético', $l['magnetFlap'], $l['capaD']);
        }

        if ($l['sideFlapCount'] > 0) {
            $capa[] = $this->peca('Aba lateral', $l['sideFlap'], $l['capaD'], (int) $l['sideFlapCount']);
        }

        $capaAberta = 2 * $l['capaW'] + $l['lombada'] + $l['aba'] + $l['magnetFlap']
            + $l['dobradicas'] * $l['canaleta'];

        $notas = [
            'As medidas informadas são as INTERNAS do berço — o vão útil precifiado.',
            sprintf(
                'Canaleta entre os painéis da capa: %.1f mm. Ela é montada na colagem, não cortada — '
                .'o papel de revestimento atravessa o vão e forma a dobradiça.',
                $l['canaleta'],
            ),
            self::AVISO_FORRO,
        ];

        if ($model->isMagnet()) {
            $notas[] = sprintf(
                'Embutir %d ímã(s) entre o cinza e o revestimento, na ponta da aba e no berço. '
                .'CONFERIR A POLARIDADE antes de colar: invertido, o fecho repele em vez de prender.',
                $model->suggestedMagnets(),
            );
        }

        return [
            'structure' => [
                ...$capa,
                ...$this->rigidBoxPanels('Berço', $w, $h, $d, $t),
            ],
            'wrap' => [
                $this->peca(
                    'Revestimento da capa (folha única)',
                    $capaAberta + 2 * self::TURN_IN_MM,
                    $l['capaD'] + 2 * self::TURN_IN_MM,
                ),
                $this->wrapSheet('Revestimento do berço', $w, $h, $d, $t),
                $this->innerLining('Forro interno do fundo do berço', $w, $d, $t),
            ],
            'notes' => $notas,
        ];
    }

    /**
     * Cartonagem dobrada: uma chapa só, vincada.
     *
     * Não há decomposição em painéis — o operador corta o retângulo e a faca
     * marca os vincos. A medida que importa é a da chapa.
     */
    private function foldedTemplate(
        BlankCalculator $calculator,
        BoxModel $model,
        float $w,
        float $h,
        float $d,
        ?array $lidMm,
    ): array {
        $blank = $calculator->blankDimensions($model, $w, $h, $d, $lidMm);

        return [
            'structure' => [
                $this->peca('Chapa única (planificação)', $blank['width'], $blank['height']),
            ],
            'wrap' => [],
            'notes' => [
                'Cartonagem dobrada: uma chapa só, com os vincos marcados pela faca.',
                'As medidas informadas são as INTERNAS da caixa.',
            ],
        ];
    }

    private const AVISO_FORRO = 'O forro interno do fundo NÃO está incluído no preço deste orçamento — '
        .'confira antes de retirar o material do estoque.';

    /**
     * Os cinco painéis de uma caixa rígida de quatro paredes.
     *
     * @return list<array{name: string, width_mm: float, height_mm: float, quantity: int}>
     */
    private function rigidBoxPanels(string $prefixo, float $w, float $h, float $d, float $t): array
    {
        return [
            $this->peca("{$prefixo} — fundo", $w, $d),
            $this->peca("{$prefixo} — parede frente/trás", $w, $h, 2),
            // Envolvem a espessura das paredes frente/trás: por isso são mais
            // longas em uma espessura de cada lado.
            $this->peca("{$prefixo} — parede lateral", $d + 2 * $t, $h, 2),
        ];
    }

    /**
     * Folha de revestimento externo — a cruz do painel mais a vira.
     *
     * A mesma conta de BlankCalculator::rigidWrapPanel(): é ela que foi
     * cobrada, e é ela que precisa ser cortada.
     */
    private function wrapSheet(string $nome, float $w, float $h, float $d, float $t): array
    {
        return $this->peca(
            $nome,
            $w + 2 * $h + 2 * $t + 2 * self::TURN_IN_MM,
            $d + 2 * $h + 2 * $t + 2 * self::TURN_IN_MM,
        );
    }

    /** Forro interno do fundo: 2mm menor para assentar sem encavalar as viradas. */
    private function innerLining(string $nome, float $w, float $d, float $t): array
    {
        return $this->peca(
            $nome,
            max($w - $t - self::INNER_LINING_CLEARANCE_MM, 0.0),
            max($d - $t - self::INNER_LINING_CLEARANCE_MM, 0.0),
        );
    }

    /** @return array{name: string, width_mm: float, height_mm: float, quantity: int} */
    /**
     * Modelo livre: o gabarito É a lista que o usuário digitou.
     *
     * Nenhuma derivação, e é o ponto. Nos outros modelos este serviço recalcula
     * as peças a partir das mesmas funções de layout que precificam, para que a
     * ficha e o preço não possam divergir. Aqui não há o que recalcular: as
     * medidas vêm do snapshot do orçamento, que é a mesma lista que o motor
     * somou. Reprocessá-las de qualquer outra forma criaria a divergência que
     * o resto da classe existe para impedir.
     *
     * Vem do SNAPSHOT e não da tabela `quote_custom_parts` de propósito — as
     * linhas continuam editáveis, e a produção precisa cortar o que foi
     * vendido, não o que alguém ajustou depois de a proposta ter saído.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return array{structure: list<array<string, mixed>>, wrap: list<array<string, mixed>>, notes: list<string>}
     */
    private function freeTemplate(array $parts): array
    {
        $estrutura = [];
        $revestimento = [];

        foreach ($parts as $part) {
            $peca = $this->peca(
                (string) ($part['name'] ?? 'Peça'),
                (float) ($part['width_mm'] ?? 0),
                (float) ($part['length_mm'] ?? 0),
                (int) ($part['quantity'] ?? 1),
            );

            // O material de cada peça entra na linha: no modelo livre eles
            // variam peça a peça, e quem separa as pilhas na bancada precisa
            // saber qual chapa pegar.
            $peca['material'] = $part['material_name'] ?? null;

            if (($part['role'] ?? 'structure') === 'wrap') {
                $revestimento[] = $peca;
            } else {
                $estrutura[] = $peca;
            }
        }

        return [
            'structure' => $estrutura,
            'wrap' => $revestimento,
            'notes' => [
                'Modelo livre: estas são as medidas informadas no orçamento, sem derivação.',
                'As quantidades são POR CAIXA — multiplique pelo tamanho do lote.',

                /*
                 * O aviso que substitui todos os outros. Nos modelos com
                 * geometria, o sistema garante que a peça cortada é a peça
                 * vendida. Aqui essa garantia é de quem mediu, e dizê-lo é mais
                 * honesto do que deixar a ficha parecer conferida.
                 */
                'A conferência das medidas é de quem as informou: o sistema não '
                    .'valida se as peças fecham uma caixa.',
            ],
        ];
    }

    private function peca(string $nome, float $largura, float $altura, int $quantidade = 1): array
    {
        return [
            'name' => $nome,
            // Décimo de milímetro: é a resolução da guilhotina de cartonagem.
            // Mais casas dariam falsa precisão a quem lê a ficha na bancada.
            'width_mm' => round($largura, 1),
            'height_mm' => round($altura, 1),
            'quantity' => $quantidade,
        ];
    }
}

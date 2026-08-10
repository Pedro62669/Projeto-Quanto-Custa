<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Modelos de embalagem suportados.
 *
 * Cada modelo tem uma planificação (blank) diferente, ou seja, uma fórmula
 * distinta para converter altura/largura/profundidade em área de material.
 * A fórmula vive em App\Services\Pricing\BlankCalculator — este enum apenas
 * nomeia o modelo e descreve seu comportamento para a UI.
 */
enum BoxModel: string
{
    /** Caixa maleta / americana (Regular Slotted Container) — 4 abas em cima e embaixo. */
    case Rsc = 'rsc';

    /** Bandeja / caixa com tampa solta (base + tampa telescópica). */
    case Tray = 'tray';

    /** Luva ou cinta que envolve o produto (sem tampo nem fundo). */
    case Sleeve = 'sleeve';

    /** Saco / envelope: duas faces planas seladas nas bordas. */
    case Pouch = 'pouch';

    /**
     * Tubo / lata cilíndrica: corpo enrolado, fundo em disco e tampa.
     *
     * Um cilindro não tem largura e profundidade independentes — tem
     * diâmetro. O modelo reaproveita `width_mm` como diâmetro, e a
     * profundidade é ignorada: para um cilindro, ambas SÃO o diâmetro
     * (é literalmente a caixa envolvente da peça), então não há distorção
     * semântica em gravar as duas iguais.
     */
    case Tube = 'tube';

    /**
     * Caixa gaveta (tipo fósforo): luva externa + gaveta que desliza dentro.
     *
     * São duas peças independentes. As dimensões informadas são as INTERNAS
     * da gaveta — o espaço útil —, e a luva é dimensionada em volta dela,
     * vencendo a espessura das paredes e a folga de deslize.
     */
    case Drawer = 'drawer';

    /**
     * Mailer box (RETT — roll end tuck top): a caixa de e-commerce.
     *
     * Uma chapa só, cortada em faca e dobrada sem cola: fundo, paredes e
     * tampa saem da mesma peça. A tampa é ARTICULADA na parede traseira — não
     * é peça separada — e fecha por uma banda frontal com lingueta que entra
     * na caixa.
     *
     * O que define o custo é o ROLO: as laterais e a parede frontal sobem,
     * dobram 180° no topo e descem por dentro, formando parede dupla de canto
     * liso. É daí que vem a rigidez do modelo — e o motivo de uma mailer
     * consumir bem mais material que um RSC de mesma medida.
     */
    case Mailer = 'mailer';

    /**
     * Caixa tampa solta (telescópica clássica) — CARTONAGEM RÍGIDA.
     *
     * Não confundir com `Tray`, que é a mesma silhueta em cartonagem DOBRADA:
     * uma chapa vincada que o próprio material sustenta. Aqui a construção é
     * outra, e é ela que define o custo.
     *
     * Duas peças (base e tampa), cada uma montada em papelão cinza cortado e
     * colado — o cinza não vinca, ele é esquadrejado e unido — e depois
     * revestida com papel que cobre o lado de fora e VIRA para dentro sobre
     * todas as bordas. Daí as duas áreas diferentes: o revestimento sempre
     * precisa de mais chapa que a estrutura que ele cobre.
     *
     * A tampa é telescópica: encaixa por fora da base, e por isso nasce maior
     * em largura e profundidade — a folga vence a espessura das paredes dela
     * própria mais o vão de encaixe.
     */
    case RigidTelescopic = 'rigid_telescopic';

    /**
     * Caixa livro — CARTONAGEM RÍGIDA, capa inteiriça.
     *
     * Duas partes que se comportam como uma: uma CAPA de três painéis de
     * papelão cinza (contracapa, lombada, tampa) e um BERÇO de quatro paredes
     * colado sobre a contracapa.
     *
     * O que faz dela "livro" é que os três painéis da capa NÃO se tocam: entre
     * eles há uma canaleta, e quem une os painéis é o próprio papel de
     * revestimento, que atravessa a fenda e vira a dobradiça. Por isso a
     * canaleta não consome papelão — ela é vazio — mas consome revestimento,
     * que precisa cobrir o vão inteiro. É a distinção que separa a área de
     * cinza da área de papel neste modelo.
     */
    case RigidBook = 'rigid_book';

    /**
     * Caixa livro com aba de fechamento.
     *
     * Um quarto painel, articulado na borda da tampa, que desce sobre a lateral
     * aberta e prende a caixa fechada por atrito. Custa uma canaleta a mais no
     * revestimento e um painel a mais de cinza — e é o que a torna transportável
     * sem fita.
     */
    case RigidBookFlap = 'rigid_book_flap';

    /**
     * Caixa ímã — capa de livro com aba frontal e fecho magnético.
     *
     * A aba desce da tampa sobre a parede frontal e prende por ímãs embutidos
     * entre o cinza e o revestimento: dois na aba, dois no berço, atraindo-se
     * através do papel. É por isso que a aba é um painel INTEIRO de cinza e não
     * uma dobra de papel — ela precisa de corpo para alojar o ímã sem
     * estufar a superfície.
     *
     * A QUANTIDADE de ímãs não define a variação: ela vem da lista de
     * materiais, onde o ímã é ferragem cobrada por peça. Três modelos que
     * diferissem só no número de ímãs seriam o mesmo modelo três vezes.
     */
    case RigidMagnet = 'rigid_magnet';

    /**
     * Caixa ímã com abas laterais.
     *
     * Além da frontal, duas abas descem pelas laterais e se recolhem para
     * dentro ao fechar. Fecham o vão que a aba frontal sozinha deixa nos
     * cantos — é o modelo de quem embala perfume e joia, onde poeira importa.
     */
    case RigidMagnetSide = 'rigid_magnet_side';

    /**
     * Caixa ímã de aba envolvente.
     *
     * A aba não para na parede frontal: desce por ela e ainda dobra sob o
     * fundo, envolvendo a peça. Consome muito mais capa — e é o acabamento de
     * caixa de convite e edição limitada, onde a superfície contínua é o
     * produto.
     */
    case RigidMagnetWrap = 'rigid_magnet_wrap';

    public function label(): string
    {
        return match ($this) {
            self::Rsc => 'Caixa americana (RSC)',
            self::Tray => 'Caixa com tampa',
            self::Sleeve => 'Luva / cinta',
            self::Pouch => 'Saco / envelope',
            self::Tube => 'Tubo / lata cilíndrica',
            self::Drawer => 'Caixa gaveta',
            self::Mailer => 'Mailer box (e-commerce)',
            self::RigidTelescopic => 'Caixa tampa solta (rígida)',
            self::RigidBook => 'Caixa livro (rígida)',
            self::RigidBookFlap => 'Caixa livro com aba (rígida)',
            self::RigidMagnet => 'Caixa ímã (rígida)',
            self::RigidMagnetSide => 'Caixa ímã com abas laterais',
            self::RigidMagnetWrap => 'Caixa ímã de aba envolvente',
        };
    }

    /**
     * Família da capa rígida: painéis articulados + berço colado.
     *
     * Livro e ímã compartilham a construção inteira — a diferença está nas abas
     * e no fecho. Por isso dividem o mesmo layout, o mesmo blank e o mesmo
     * componente 3D.
     */
    public function isBook(): bool
    {
        return in_array($this, [
            self::RigidBook, self::RigidBookFlap,
            self::RigidMagnet, self::RigidMagnetSide, self::RigidMagnetWrap,
        ], true);
    }

    /** Família ímã: a aba frontal aloja o fecho magnético. */
    public function isMagnet(): bool
    {
        return in_array($this, [
            self::RigidMagnet, self::RigidMagnetSide, self::RigidMagnetWrap,
        ], true);
    }

    /** A capa tem um quarto painel que fecha a lateral aberta (só o livro). */
    public function hasClosingFlap(): bool
    {
        return $this === self::RigidBookFlap;
    }

    /**
     * Ímãs sugeridos para o formulário.
     *
     * SUGESTÃO e não regra: quem cobra é a lista de materiais, onde o usuário
     * informa a quantidade. O número aqui só evita que ele comece do zero e
     * esqueça de lançar a ferragem — o esquecimento mais caro do modelo,
     * porque o ímã não aparece na foto da caixa.
     */
    public function suggestedMagnets(): int
    {
        return match ($this) {
            self::RigidMagnet, self::RigidMagnetWrap => 2,
            // As laterais pedem um par extra para prender os cantos.
            self::RigidMagnetSide => 4,
            default => 0,
        };
    }

    /**
     * Cartonagem RÍGIDA: papelão cinza revestido com papel, em vez de uma
     * chapa vincada que se sustenta sozinha.
     *
     * Quem consome: o BlankCalculator (que devolve duas áreas em vez de uma) e
     * a UI, que só oferece o campo de material de revestimento nestes modelos.
     */
    public function isRigid(): bool
    {
        return $this === self::RigidTelescopic || $this->isBook();
    }

    /**
     * Modelos cuja seção é circular.
     *
     * Quem consome: a UI (renomeia "Largura" para "Diâmetro" e esconde a
     * profundidade) e o motor (usa a largura como diâmetro).
     */
    public function isCylindrical(): bool
    {
        return $this === self::Tube;
    }

    /** Modelos que têm uma tampa como peça separada. */
    public function hasSeparateLid(): bool
    {
        return in_array($this, [self::Tray, self::Tube, self::RigidTelescopic], true);
    }

    /**
     * Minutos de produção sugeridos por unidade — serve apenas como valor
     * inicial do formulário; o usuário pode sobrescrever.
     */
    public function defaultProductionMinutes(): float
    {
        return match ($this) {
            self::Rsc => 2.5,
            self::Tray => 4.0,
            self::Sleeve => 1.2,
            self::Pouch => 1.0,
            self::Tube => 3.0,
            // Duas peças montadas separadamente: leva mais tempo que um RSC.
            self::Drawer => 4.5,
            // Sai da faca pronta: dobra e trava, sem cola nem fita. É o
            // modelo mais rápido de montar entre as caixas rígidas.
            self::Mailer => 2.0,
            /*
             * O modelo mais lento da lista, e por larga margem: são duas peças
             * de cinza esquadrejadas e coladas, e depois duas operações de
             * revestimento com virada em todas as bordas. Revestir é trabalho
             * manual que não tem como acelerar com faca melhor.
             */
            self::RigidTelescopic => 12.0,

            /*
             * Mais lenta que a tampa solta: além das duas operações de
             * revestimento, a capa exige posicionar três painéis com a canaleta
             * exata antes de baixar o papel. Errar a canaleta em um milímetro
             * faz a caixa não fechar, e não há como corrigir depois de colada.
             */
            self::RigidBook => 15.0,
            self::RigidBookFlap => 17.0,

            /*
             * O ímã acrescenta uma operação que não existe nos outros modelos:
             * embutir a peça ENTRE o cinza e o revestimento, com polaridade
             * certa. Inverter um ímã faz a caixa repelir em vez de fechar, e o
             * erro só aparece depois de colado — daí o tempo extra ser de
             * conferência, não de montagem.
             */
            self::RigidMagnet => 19.0,
            self::RigidMagnetSide => 22.0,
            self::RigidMagnetWrap => 21.0,
        };
    }
}

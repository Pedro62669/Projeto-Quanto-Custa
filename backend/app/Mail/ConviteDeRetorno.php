<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * E-mail de reengajamento para quem sumiu.
 *
 * O conteúdo é deliberadamente ÚTIL e não promocional: lembra que custo de
 * matéria-prima muda e que preço calculado com número velho é prejuízo com cara
 * de lucro. Um "sentimos sua falta" vazio treina o destinatário a ignorar o
 * remetente — e o remetente aqui é o mesmo que manda o aviso de fatura.
 *
 * Carrega link de descadastro em um clique, obrigatório: é comunicação de
 * marketing, e a LGPD exige oposição facilitada (art. 18, §2º).
 */
class ConviteDeRetorno extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly User $usuario) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Seus custos ainda estão atualizados no Quanto-Custa?',
        );
    }

    public function content(): Content
    {
        /*
         * URL assinada e SEM validade.
         *
         * Sem validade porque um link de descadastro que expira é um
         * descadastro que falha justamente para quem demorou a se incomodar —
         * e aí o titular reclama no lugar de clicar. Assinada porque, sem
         * assinatura, o id na URL deixaria qualquer um descadastrar qualquer
         * pessoa por força bruta.
         */
        $linkDeDescadastro = URL::signedRoute('engajamento.descadastro', [
            'user' => $this->usuario->id,
        ]);

        return new Content(
            markdown: 'emails.convite-de-retorno',
            with: [
                'nome' => $this->usuario->name,
                'diasSemAcesso' => $this->usuario->last_login_at?->diffInDays(now()),
                'linkDeDescadastro' => $linkDeDescadastro,
            ],
        );
    }
}

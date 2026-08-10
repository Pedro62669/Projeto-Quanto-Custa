{{--
    E-mail de reengajamento.

    Conteúdo útil, não promocional: quem recebe "sentimos sua falta" três vezes
    aprende a ignorar o remetente — e o remetente é o mesmo que manda o aviso de
    fatura. Aqui o argumento é o do próprio negócio dele.
--}}
<x-mail::message>
# Olá, {{ $nome }}

@if ($diasSemAcesso)
Faz {{ (int) $diasSemAcesso }} dias que você não abre o Quanto-Custa.
@else
Faz um tempo que você não abre o Quanto-Custa.
@endif

Nesse intervalo, é bem provável que o preço de alguma matéria-prima sua tenha
mudado. E aí mora o problema silencioso da cartonagem: **o orçamento continua
saindo, continua bonito e continua sendo aprovado — só que calculado com o custo
do papelão de dois meses atrás.** O prejuízo só aparece no fim do mês, quando o
caixa não fecha com a margem que a planilha prometia.

Vale dez minutos:

- Confira o preço da chapa e do papel de revestimento
- Revise as despesas fixas — elas entram na sua hora-empresa
- Rode um orçamento antigo de novo e compare o preço de hoje

<x-mail::button :url="config('app.frontend_url', config('app.url'))">
Atualizar meus custos
</x-mail::button>

Qualquer dúvida, é só responder este e-mail.

Abraço,<br>
Equipe {{ config('app.name') }}

<x-slot:subcopy>
Você recebeu este e-mail porque tem uma conta no {{ config('app.name') }}.
Se preferir não receber mais estes lembretes,
[cancele o recebimento aqui]({{ $linkDeDescadastro }}) — os avisos de cobrança e
de segurança continuam chegando normalmente.
</x-slot:subcopy>
</x-mail::message>

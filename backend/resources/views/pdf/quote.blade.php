{{--
    Orçamento comercial — o documento que chega ao cliente final.

    Escrito para o dompdf, que NÃO é um navegador: sem flexbox, sem grid, sem
    variáveis CSS. O layout usa tabelas de propósito — é o único recurso que ele
    posiciona de forma previsível, e um PDF que quebra na casa do cliente é pior
    que um PDF feio.

    Nenhum custo aparece aqui. Preço unitário e total, sim; material, mão de
    obra e margem, nunca — ver QuotePdfGenerator.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Orçamento {{ $quote->reference }}</title>
    <style>
        /* DejaVu Sans: a única fonte que o dompdf embute com acentuação
           correta sem configuração extra. Trocá-la por uma web font faz
           "ç" e "ã" virarem quadrados no arquivo final. */
        * { font-family: "DejaVu Sans", sans-serif; }

        @page { margin: 22mm 16mm; }

        body { font-size: 10.5pt; color: #1F2328; line-height: 1.45; }

        .marca { width: 100%; border-bottom: 2px solid #C8A06A; padding-bottom: 10px; }
        .marca td { vertical-align: top; }
        .marca .logo { width: 130px; }
        .marca .logo img { max-width: 130px; max-height: 60px; }
        .marca .nome { font-size: 15pt; font-weight: bold; color: #1F2328; }
        .marca .contato { font-size: 8.5pt; color: #5B6470; }

        h1 { font-size: 13pt; margin: 22px 0 2px; }
        .ref { font-size: 9pt; color: #5B6470; margin-bottom: 16px; }

        .bloco { width: 100%; margin-bottom: 16px; }
        .bloco th {
            text-align: left; font-size: 8pt; text-transform: uppercase;
            letter-spacing: .06em; color: #5B6470; padding-bottom: 3px;
        }
        .bloco td { padding: 2px 0; }

        table.itens { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.itens th {
            background: #F4F1EC; text-align: left; padding: 7px 9px;
            font-size: 8.5pt; text-transform: uppercase; letter-spacing: .05em;
            border-bottom: 1px solid #E2DCD2;
        }
        table.itens td { padding: 9px; border-bottom: 1px solid #EFEAE2; }
        .num { text-align: right; }

        .total { background: #F4F1EC; font-weight: bold; font-size: 12pt; }

        .rodape { margin-top: 26px; font-size: 9pt; color: #5B6470; }
        .rodape strong { color: #1F2328; }

        /* Uma condição de pagamento partida entre duas páginas confunde quem
           lê — e é justamente a parte que o cliente confere duas vezes. */
        .evitar-quebra { page-break-inside: avoid; }
    </style>
</head>
<body>

{{-- ── Cabeçalho: a marca do assinante, não a do sistema ──────────────── --}}
<table class="marca">
    <tr>
        @if ($logo)
            <td class="logo"><img src="{{ $logo }}" alt=""></td>
        @endif
        <td>
            <div class="nome">{{ $tenant->legal_name ?: $tenant->name }}</div>
            <div class="contato">
                @if ($tenant->document)
                    CNPJ/CPF: {{ $tenant->document }}<br>
                @endif
                @if ($tenant->street)
                    {{ $tenant->street }}{{ $tenant->street_number ? ', '.$tenant->street_number : '' }}
                    @if ($tenant->district), {{ $tenant->district }} @endif
                    <br>
                @endif
                @if ($tenant->city)
                    {{ $tenant->city }}{{ $tenant->state ? '/'.$tenant->state : '' }}
                    @if ($tenant->postal_code) — CEP {{ $tenant->postal_code }} @endif
                    <br>
                @endif
                @if ($tenant->whatsapp) WhatsApp: {{ $tenant->whatsapp }} @endif
                @if ($tenant->email) · {{ $tenant->email }} @endif
                @if ($tenant->instagram) · {{ $tenant->instagram }} @endif
            </div>
        </td>
    </tr>
</table>

<h1>Proposta comercial</h1>
<div class="ref">
    {{ $quote->reference }} · Emitida em {{ $quote->created_at?->format('d/m/Y') }}
</div>

{{-- ── Cliente ────────────────────────────────────────────────────────── --}}
<table class="bloco">
    <tr><th>Cliente</th></tr>
    <tr>
        <td>
            <strong>{{ $quote->client_name }}</strong>
            @if ($quote->client_email) · {{ $quote->client_email }} @endif
            @if ($quote->client?->city)
                <br>{{ $quote->client->city }}{{ $quote->client->state ? '/'.$quote->client->state : '' }}
            @endif
        </td>
    </tr>
</table>

{{-- ── O produto ──────────────────────────────────────────────────────── --}}
<table class="bloco">
    <tr><th>Embalagem</th></tr>
    <tr>
        <td>
            <strong>{{ $quote->box_model->label() }}</strong><br>
            Medidas internas:
            {{ number_format($quote->width_mm, 0, ',', '.') }} ×
            {{ number_format($quote->depth_mm, 0, ',', '.') }} ×
            {{ number_format($quote->height_mm, 0, ',', '.') }} mm
            (largura × profundidade × altura)

            @if ($quote->lid_width_mm)
                <br>Tampa:
                {{ number_format($quote->lid_width_mm, 0, ',', '.') }} ×
                {{ number_format($quote->lid_depth_mm, 0, ',', '.') }} ×
                {{ number_format($quote->lid_height_mm, 0, ',', '.') }} mm
            @endif

            @if ($snapshot['material']['name'] ?? null)
                <br>Material: {{ $snapshot['material']['name'] }}
            @endif

            @if ($quote->hardware_cost > 0)
                <br>Com fecho e acessórios inclusos.
            @endif
        </td>
    </tr>
</table>

{{-- ── Valores ────────────────────────────────────────────────────────── --}}
<table class="itens evitar-quebra">
    <tr>
        <th>Descrição</th>
        <th class="num">Quantidade</th>
        <th class="num">Preço unitário</th>
        <th class="num">Total</th>
    </tr>
    <tr>
        <td>{{ $quote->box_model->label() }} personalizada</td>
        <td class="num">{{ number_format($quote->quantity, 0, ',', '.') }}</td>
        <td class="num">R$ {{ number_format($quote->unit_price, 2, ',', '.') }}</td>
        <td class="num">R$ {{ number_format($quote->total_price, 2, ',', '.') }}</td>
    </tr>
    <tr class="total">
        <td colspan="3">Total do lote</td>
        <td class="num">R$ {{ number_format($quote->total_price, 2, ',', '.') }}</td>
    </tr>
</table>

{{-- ── Condições ──────────────────────────────────────────────────────── --}}
<div class="rodape evitar-quebra">
    @if ($payment)
        <p>
            <strong>Condições de pagamento:</strong>
            @if ($payment['count'] === 1)
                À vista, R$ {{ number_format($payment['amount'], 2, ',', '.') }}.
            @else
                {{ $payment['count'] }}× de R$ {{ number_format($payment['amount'], 2, ',', '.') }}
                @if ($payment['last_amount'] !== $payment['amount'])
                    (última parcela de R$ {{ number_format($payment['last_amount'], 2, ',', '.') }})
                @endif
                — primeiro vencimento em {{ $payment['first_due_date']->format('d/m/Y') }}.
            @endif
        </p>
    @endif

    @if ($quote->notes)
        <p><strong>Observações:</strong> {{ $quote->notes }}</p>
    @endif

    <p>
        Proposta válida por 15 dias a partir da data de emissão.
        Prazo de produção confirmado na aprovação do pedido.
    </p>
</div>

</body>
</html>

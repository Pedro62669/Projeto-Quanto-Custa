<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Descadastro concluído — {{ config('app.name') }}</title>
    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #F4F1EC; color: #1F2328; margin: 0;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; padding: 24px;
        }
        .cartao {
            background: #fff; border-radius: 14px; padding: 40px;
            max-width: 480px; box-shadow: 0 2px 18px rgba(31, 35, 40, .08);
        }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { line-height: 1.6; color: #5B6470; margin: 0 0 12px; }
        strong { color: #1F2328; }
    </style>
</head>
<body>
<div class="cartao">
    <h1>Pronto, não mandamos mais.</h1>

    <p>
        O endereço <strong>{{ $email }}</strong> não vai mais receber os lembretes
        de atualização de custos.
    </p>

    {{--
        Dizer o que CONTINUA chegando é obrigação de transparência e também
        evita o suporte de amanhã: quem some da lista inteira estranha não
        receber o aviso da própria fatura.
    --}}
    <p>
        Avisos de cobrança, de segurança e respostas a solicitações suas continuam
        sendo enviados — eles não são comunicação de marketing.
    </p>

    @if ($desde)
        <p>Registrado em {{ $desde->format('d/m/Y \à\s H:i') }}.</p>
    @endif
</div>
</body>
</html>

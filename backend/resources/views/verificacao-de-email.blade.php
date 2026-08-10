<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sucesso ? 'E-mail confirmado' : 'Link inválido' }} — {{ config('app.name') }}</title>
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
        p { line-height: 1.6; color: #5B6470; margin: 0 0 14px; }
        strong { color: #1F2328; }
        .botao {
            display: inline-block; margin-top: 8px; padding: 11px 20px;
            background: #C8A06A; color: #fff; text-decoration: none;
            border-radius: 8px; font-weight: 600;
        }
    </style>
</head>
<body>
<div class="cartao">
    @if ($sucesso)
        <h1>{{ $jaVerificado ? 'Este e-mail já estava confirmado.' : 'E-mail confirmado.' }}</h1>

        <p>
            O endereço <strong>{{ $email }}</strong> está verificado. Você já pode
            assinar um plano quando quiser.
        </p>

        <a class="botao" href="{{ config('app.frontend_url') }}">Ir para o sistema</a>
    @else
        <h1>Este link não vale mais.</h1>

        {{--
            Não distinguimos "usuário inexistente" de "hash não confere": a
            diferença só interessaria a quem estivesse testando ids alheios.
        --}}
        <p>
            O link pode ter expirado ou o endereço de e-mail pode ter mudado
            desde que ele foi enviado.
        </p>

        <p>
            Entre no sistema e peça um novo e-mail de confirmação — leva um
            clique.
        </p>

        <a class="botao" href="{{ config('app.frontend_url') }}">Ir para o sistema</a>
    @endif
</div>
</body>
</html>

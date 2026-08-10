<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as RegraDeSenha;

/**
 * "Esqueci minha senha" — o caminho de volta do assinante.
 *
 * Até existir, o único jeito de recuperar uma senha era o operador da
 * plataforma apertar o botão em /api/platform: quem esquecia ficava trancado
 * para fora da própria empresa até alguém atender o telefone.
 *
 * Duas rotas PÚBLICAS, e nisso está o cuidado principal do arquivo: quem chama
 * não está autenticado, por definição. Toda a superfície aqui é anônima, e as
 * respostas são desenhadas para não contar a um estranho o que ele não sabia
 * antes — em especial, quais e-mails existem na base.
 */
class PasswordResetController extends Controller
{
    /**
     * Resposta única, sucesso ou não.
     *
     * `Password::sendResetLink()` distingue "enviado" de "usuário inexistente",
     * e repassar essa diferença transformaria a rota num verificador de contas:
     * qualquer pessoa descobriria, um e-mail por vez, quem é cliente do sistema.
     * É a mesma decisão que o AuthController já toma na mensagem de login
     * inválido.
     */
    private const RESPOSTA_NEUTRA = 'Se houver uma conta com este e-mail, o link de redefinição já está a caminho. '
        .'Ele vale por uma hora.';

    /**
     * Envia o link de redefinição.
     *
     * O endereço do link aponta para o Next.js, não para a API — ver
     * `ResetPassword::createUrlUsing` no AppServiceProvider. Aqui é headless: o
     * Laravel não serve tela, e um link para a API abriria JSON no navegador de
     * quem clicou.
     */
    public function sendLink(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
        ]);

        /*
         * `is_active` entra nas credenciais de propósito.
         *
         * O provider do Eloquent transforma isto em cláusula WHERE, então conta
         * desativada simplesmente não é encontrada e nenhum e-mail sai. Mandar
         * link para quem foi suspenso seria convidar de volta alguém que o
         * sistema vai barrar no login seguinte — e a mensagem neutra garante que
         * a diferença não vaza para quem pediu.
         */
        Password::sendResetLink([
            'email' => $dados['email'],
            'is_active' => true,
        ]);

        return response()->json(['message' => self::RESPOSTA_NEUTRA]);
    }

    /**
     * Troca a senha usando o token do e-mail.
     */
    public function reset(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', RegraDeSenha::min(8)],
        ]);

        $status = Password::reset($dados, function (User $user, string $senha): void {
            $user->forceFill([
                'password' => $senha,

                /*
                 * remember_token novo invalida o cookie "lembrar de mim" que
                 * possa ter ficado num navegador alheio.
                 */
                'remember_token' => Str::random(60),
            ])->save();

            /*
             * Derruba TODAS as sessões abertas.
             *
             * Quem redefine a senha quase sempre o faz porque perdeu o controle
             * dela. Deixar os tokens do Sanctum vivos manteria a sessão de quem
             * a roubou funcionando por mais sete dias, e a redefinição viraria
             * teatro. É o mesmo cuidado do PlatformUserController.
             */
            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            /*
             * Token inválido, expirado ou de outro e-mail.
             *
             * Aqui a mensagem PODE ser específica, e a diferença em relação ao
             * envio é deliberada: quem chega nesta rota já tem um token em mãos,
             * então nada se revela sobre a existência da conta. E "o link
             * expirou" é acionável — "não foi possível" faria a pessoa tentar o
             * mesmo link de novo.
             */
            return response()->json([
                'message' => 'Este link de redefinição não vale mais. '
                    .'Ele expira em uma hora e só pode ser usado uma vez — peça um novo.',
                'error' => 'token_invalido',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Senha alterada. Todas as sessões anteriores foram encerradas — '
                .'entre novamente com a senha nova.',
        ]);
    }
}

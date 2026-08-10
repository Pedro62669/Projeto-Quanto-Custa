<?php

namespace App\Providers;

use App\Services\Billing\Gateways\FakeGateway;
use App\Services\Billing\Gateways\PaymentGateway;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Gateway de pagamento resolvido por configuração.
         *
         * Todo o domínio de cobrança depende da interface, nunca de um driver
         * concreto — é o que permite testar a regra dos 7 dias do CDC sem conta
         * em provedor nenhum, e trocar de provedor sem tocar em regra de
         * negócio. Ver config/billing.php.
         */
        $this->app->singleton(PaymentGateway::class, function (): PaymentGateway {
            $driver = (string) config('billing.driver', 'fake');
            $segredo = (string) config('billing.webhook_secret');

            return match ($driver) {
                'fake' => new FakeGateway($segredo),
                default => throw new \InvalidArgumentException(
                    "Gateway de pagamento não suportado: {$driver}."
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * O link de redefinição de senha aponta para o Next.js, não para a API.
         *
         * A arquitetura é headless: o Laravel não serve tela nenhuma, e o link
         * padrão do framework monta a URL a partir de uma rota web `password.reset`
         * que aqui não existe. Sem esta sobrescrita, o e-mail sairia com um
         * endereço quebrado — ou nem sairia, estourando na geração da rota.
         */
        /*
         * NÃO registre SendEmailVerificationNotification aqui.
         *
         * O framework já amarra esse listener ao evento Registered sozinho, no
         * `configureEmailVerification()` do EventServiceProvider dele — basta
         * `User implements MustVerifyEmail`, que é o que existe. Registrar de
         * novo não substitui: SOMA. Cada cadastro passa a disparar dois e-mails
         * de confirmação idênticos, e o sintoma chega como reclamação de cliente
         * novo, não como erro em log. Ver CadastroDeEmpresaTest, que trava a
         * contagem em um.
         */
        ResetPassword::createUrlUsing(
            fn (object $notifiable, string $token): string => rtrim((string) config('app.frontend_url'), '/')
                ."/redefinir-senha?token={$token}&email=".urlencode($notifiable->getEmailForPasswordReset()),
        );
    }
}

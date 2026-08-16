<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al dueño REAL de un correo cuando alguien intenta registrarse con él y
 * la cuenta ya existe (y ya está verificada).
 *
 * Es la pieza anti-enumeración del registro: en vez de responderle al que
 * registra "ese correo ya tiene cuenta" (que filtraría existencia), se le
 * responde lo MISMO que a un alta nueva y se avisa por este correo al titular.
 * Así, quien prueba correos no distingue "existe" de "no existe", y el dueño
 * legítimo se entera y sabe cómo entrar.
 */
class AccountAlreadyExistsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject('Ya tienes una cuenta en '.config('app.name'))
            ->markdown('emails.auth.account-exists', [
                'loginUrl' => $frontend.'/login',
                'forgotUrl' => $frontend.'/forgot-password',
                'supportEmail' => config('mail.reply_to.address', 'hello@funcionbase.com'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}

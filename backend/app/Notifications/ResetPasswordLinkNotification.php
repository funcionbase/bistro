<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Enlace de restablecimiento de contraseña. Apunta al SPA
 * (`/reset-password/{token}?email=...`).
 *
 * Sirve dos casos: recuperar una contraseña olvidada, y FIJAR contraseña por
 * primera vez en una cuenta creada con Google (habilita el acceso dual con
 * el mismo correo).
 */
class ResetPasswordLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $resetUrl = $frontend.'/reset-password/'.$this->token.'?email='.urlencode((string) $notifiable->email);
        $expires = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Restablece tu contraseña de '.config('app.name'))
            ->markdown('emails.auth.reset-password', [
                'resetUrl' => $resetUrl,
                'expiresInMinutes' => $expires,
                'supportEmail' => config('mail.reply_to.address', 'hello@funcionbase.com'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}

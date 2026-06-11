<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo enviado al correo ACTUAL (viejo) de una cuenta cuando alguien intenta
 * recuperarla hacia un correo nuevo (flujo de cédula ya registrada).
 *
 * Es la prueba de identidad: solo quien controla este correo puede confirmar el
 * cambio. Si el titular no pidió esto, ignora el correo y nada cambia.
 */
class AccountEmailChangeRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $confirmUrl,
        private readonly string $newEmail,
        private readonly int $expiresInMinutes,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirma el cambio de correo de tu cuenta flexyflow')
            ->markdown('emails.auth.email-change-requested', [
                'confirmUrl' => $this->confirmUrl,
                'newEmail' => $this->newEmail,
                'expiresInMinutes' => $this->expiresInMinutes,
                'supportEmail' => config('mail.reply_to.address', 'soporte@flexyflow.co'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'new_email' => $this->newEmail,
        ];
    }
}

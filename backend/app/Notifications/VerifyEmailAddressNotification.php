<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Enlace de verificación de correo para cuentas registradas con
 * correo/contraseña. Sin verificar, el usuario puede iniciar sesión pero NO
 * registrar empresa (gate en CompanyEnrollmentController).
 *
 * La URL es firmada y temporal (`verification.verify`, 60 min) — la
 * construye el caller para que la notificación quede serializable en cola
 * sin depender del request.
 */
class VerifyEmailAddressNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $verifyUrl,
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
            ->subject('Verifica tu correo para continuar en '.config('app.name'))
            ->markdown('emails.auth.verify-email', [
                'verifyUrl' => $this->verifyUrl,
                'expiresInMinutes' => $this->expiresInMinutes,
                'supportEmail' => config('mail.reply_to.address', 'soporte@flexyflow.co'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}

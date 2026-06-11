<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo al usuario invitado a una empresa.
 *
 * Avisa al invitado que un propietario/admin de una empresa (identificada por
 * NIT) le otorgó acceso. Incluye CTA al login: la aceptación es automática
 * vía email auto-match en `InvitedEnrollmentController` apenas el usuario
 * autentica con el mismo correo (no se expone el `token` de la invitación
 * en la URL; el token sigue siendo el respaldo de auditoría).
 *
 * El envío real lo orquesta `SendUserInvitationEmailJob`, que es el
 * entrypoint queueable + ShouldBeUnique. Esta clase NO implementa
 * `ShouldQueue` a propósito: el Job ya garantiza idempotencia N-instance
 * y usa `Notification::sendNow` para evitar doble-encolado.
 */
class UserInvitedNotification extends Notification
{
    public function __construct(
        public readonly CompanyInvitation $invitation,
        public readonly Company $company,
        public readonly ?User $invitedBy = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Para los `Notification::route('mail', $email)` el `notifiable` es un
     * `AnonymousNotifiable`. No tenemos `User` previo porque el invitado puede
     * aún no existir en la BD. Por eso usamos rutas anónimas y omitimos el
     * nombre — el copy del correo va dirigido al correo, no a un nombre.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $inviterName = $this->invitedBy?->first_name
            ?? $this->invitedBy?->name
            ?? 'el equipo';

        return (new MailMessage)
            ->subject('Te invitaron a '.$this->company->commercial_name.' en flexyflow')
            ->markdown('emails.invitations.user-invited', [
                'invitation' => $this->invitation,
                'company' => $this->company,
                'inviterName' => $inviterName,
                'loginUrl' => rtrim((string) config('app.frontend_url'), '/').'/',
                'supportEmail' => config('mail.reply_to.address', 'soporte@flexyflow.co'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'company_nit' => $this->company->nit,
            'invitation_id' => $this->invitation->id,
            'email' => $this->invitation->email,
        ];
    }
}

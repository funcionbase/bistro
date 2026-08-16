<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo al usuario que completó el enrolamiento de su empresa.
 *
 * Confirma que el registro fue exitoso y avisa que la empresa nace en
 * `pending_activation` (pendiente de aprobación): un workflow operativo
 * externo la transiciona a `verified` o `rejected`.
 *
 * El envío real lo orquesta `SendCompanyRegistrationWelcomeEmailJob`, que es
 * el entrypoint queueable + ShouldBeUnique. Esta clase NO implementa
 * `ShouldQueue` a propósito: el Job ya garantiza la idempotencia N-instance
 * (CA-6) y usa `Notification::sendNow` para evitar doble-encolado.
 */
class CompanyRegistrationPendingNotification extends Notification
{
    public function __construct(
        public readonly Company $company,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = $notifiable instanceof User
            ? ($notifiable->first_name ?? $notifiable->name)
            : ($notifiable->name ?? 'propietario');

        return (new MailMessage)
            ->subject('¡Tu empresa quedó registrada en bistro!')
            ->markdown('emails.enrollment.company-pending-approval', [
                'firstName' => $firstName,
                'company' => $this->company,
                'dashboardUrl' => config('app.frontend_url'),
                'supportEmail' => config('mail.reply_to.address', 'hello@funcionbase.com'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'company_nit' => $this->company->nit,
            'status' => $this->company->status,
        ];
    }
}

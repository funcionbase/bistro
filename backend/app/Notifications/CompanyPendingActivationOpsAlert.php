<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerta interna al equipo: hay una empresa nueva en `pending_activation`
 * esperando revisión.
 *
 * Se dispara junto al correo al propietario (CompanyRegistrationPendingNotification)
 * tras `CompanyEnrollmentController::store()`. El envío real lo orquesta
 * `SendCompanyPendingActivationOpsAlertJob` con `ShouldBeUnique` para evitar
 * envíos duplicados en el ASG N-instance.
 *
 * Destinatario: `config('mail.ops_alert_address')` — por default
 * `cristian@flexyflow.co`. NO usa `Notifiable` sobre un User: el destinatario
 * es un buzón fijo del equipo, no una cuenta de la plataforma.
 */
class CompanyPendingActivationOpsAlert extends Notification
{
    public function __construct(
        public readonly Company $company,
        public readonly User $owner,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[flexyflow] Nueva empresa pendiente de aprobación — {$this->company->commercial_name}")
            ->markdown('emails.enrollment.ops-alert-pending-activation', [
                'company' => $this->company,
                'owner' => $this->owner,
                'dashboardUrl' => config('app.frontend_url'),
            ]);
    }
}

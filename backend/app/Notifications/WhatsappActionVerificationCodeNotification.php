<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo al owner pidiendo confirmar una accion sobre la cuenta de WhatsApp.
 *
 * El correo SIEMPRE va al owner aunque otro usuario con permiso RBAC haya
 * disparado la accion. Asi el dueno se entera y puede rechazar con el boton
 * "No fui yo" si la solicitud no la hizo el.
 */
class WhatsappActionVerificationCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Company $company,
        public readonly User $requester,
        public readonly User $owner,
        public readonly string $action,
        public readonly string $code,
        public readonly string $rejectToken,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $actionLabel = $this->actionLabel();
        $rejectUrl = config('app.url').'/api/v1/whatsapp/verification/reject?token='.$this->rejectToken;

        return (new MailMessage)
            ->subject("Codigo de seguridad WhatsApp — {$this->company->commercial_name}")
            ->markdown('emails.whatsapp.verification-code', [
                'owner' => $this->owner,
                'requester' => $this->requester,
                'company' => $this->company,
                'action' => $this->action,
                'actionLabel' => $actionLabel,
                'code' => $this->code,
                'rejectUrl' => $rejectUrl,
                'ip' => $this->ip,
                'userAgent' => $this->userAgent,
                'expiresInMinutes' => 10,
            ]);
    }

    private function actionLabel(): string
    {
        return match ($this->action) {
            'connect' => 'conectar WhatsApp',
            'swap' => 'cambiar el numero de WhatsApp',
            'disconnect' => 'desconectar WhatsApp',
            'update' => 'actualizar la cuenta de WhatsApp',
            default => $this->action,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'company_nit' => $this->company->nit,
            'action' => $this->action,
            'requester_id' => $this->requester->id,
        ];
    }
}

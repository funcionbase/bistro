<?php

namespace App\Notifications\Channels;

use App\Services\NotificationDispatchTracker;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

/**
 * Canal de correo con dedup idempotente a nivel (notif, user).
 *
 * Envuelve el MailChannel nativo. Antes de enviar, intenta registrar el envio
 * via NotificationDispatchTracker::markDispatched() (INSERT con UNIQUE compuesto
 * notification_class + idempotency_key + user_id). Si el INSERT gana → envia.
 * Si choca con el UNIQUE → skip silencioso (ya se envio).
 *
 * Clave del diseno: este send() corre en el WORKER (las notifs billing son
 * ShouldQueue), asi que un reintento de cola del job re-ejecuta este metodo y
 * el UNIQUE bloquea el reenvio. Esa es la garantia que los markers *_notified_at
 * a nivel empresa NO pueden dar (ellos se setean al encolar, no al enviar).
 *
 * Registrado como driver 'deduped_mail' en AppServiceProvider. Las notifs
 * billing devuelven `['deduped_mail']` en via().
 */
class DedupedMailChannel
{
    public function __construct(
        private readonly MailChannel $mailChannel,
        private readonly NotificationDispatchTracker $tracker,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $this->tracker->markDispatched($notifiable, $notification)) {
            return;
        }

        $this->mailChannel->send($notifiable, $notification);
    }
}

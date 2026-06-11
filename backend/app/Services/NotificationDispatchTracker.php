<?php

namespace App\Services;

use App\Models\NotificationDispatch;
use App\Models\User;
use App\Notifications\Contracts\BillingNotificationContract;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * #257 — Despachador idempotente de notificaciones billing.
 *
 * Garantiza que un mismo evento (representado por su idempotencyKey()) NO se
 * envia dos veces al mismo user. Defensa cross-instance EC2 y — esto es lo
 * importante — contra reintentos de cola.
 *
 * Por que protege contra reintentos de cola: el INSERT a notification_dispatches
 * NO ocurre aqui al encolar, sino en el momento del ENVIO real, dentro de
 * App\Notifications\Channels\DedupedMailChannel::send(), que corre en el worker
 * cuando se procesa el job de la notification (todas las notifs billing son
 * ShouldQueue). Si el job falla a media (SMTP down) y el worker lo reintenta,
 * el canal vuelve a ejecutar markDispatched() → el INSERT choca con el UNIQUE
 * → se skipea el reenvio. Asi el dedup cubre:
 *   - reintentos de cola (worker retry del mismo job),
 *   - ejecuciones cron paralelas / dobles,
 *   - disparos manuales.
 *
 * Politica at-most-once (alineada con notifyOnce de BillingService): si el
 * envio falla DESPUES de insertar el marker, preferimos perder el mensaje a
 * disparar copias. Para reintentos manuales, ops borra la fila (soft delete)
 * o cambia la idempotency_key.
 *
 * Si la notification NO implementa BillingNotificationContract, falla con
 * InvalidArgumentException — todas las notifs billing deben implementarlo.
 */
class NotificationDispatchTracker
{
    /**
     * Encola la notification a una coleccion de users. El dedup real (INSERT
     * idempotente) ocurre en DedupedMailChannel::send() cuando el worker
     * procesa cada job — no aqui. Esto es lo que da proteccion contra
     * reintentos de cola.
     *
     * @param  iterable<User>  $users
     * @param  Notification&BillingNotificationContract  $notification
     */
    public function dispatchToUsers(iterable $users, Notification $notification): void
    {
        // Fail-fast al encolar: si la notif no cumple el contrato, no tiene
        // sentido encolar jobs que reventarian en el worker.
        $this->assertBillingNotification($notification);

        foreach ($users as $user) {
            $user->notify($notification);
        }
    }

    /**
     * Registra el envio de una notification a un notifiable de forma
     * idempotente. Llamado por DedupedMailChannel en el momento del envio
     * (dentro del worker), de modo que un retry del job re-ejecute este metodo
     * y choque con el UNIQUE.
     *
     * @param  Notification&BillingNotificationContract  $notification
     * @return bool true → INSERT exitoso, hay que enviar el correo.
     *              false → duplicado (ya enviado), skipear el correo.
     */
    public function markDispatched(object $notifiable, Notification $notification): bool
    {
        $this->assertBillingNotification($notification);

        $payload = [
            'notification_class' => $notification::class,
            'idempotency_key' => $notification->idempotencyKey(),
            'user_id' => $notifiable->getKey(),
            'company_nit' => $notification->companyNit(),
            'target_email' => $notifiable->email,
            'metadata' => $notification->dispatchMetadata(),
            'sent_at' => now(),
        ];

        try {
            NotificationDispatch::create($payload);
        } catch (UniqueConstraintViolationException) {
            Log::info('notification.dispatch_skipped_duplicate', [
                'notification_class' => $payload['notification_class'],
                'idempotency_key' => $payload['idempotency_key'],
                'user_id' => $payload['user_id'],
                'company_nit' => $payload['company_nit'],
            ]);

            return false;
        } catch (Throwable $e) {
            // Otros errores de BD (FK invalido, etc.) — log y re-throw para que
            // el job falle y reintente. NO mandamos el correo si no podemos
            // dejar el registro.
            Log::error('notification.dispatch_tracking_failed', [
                'notification_class' => $payload['notification_class'],
                'idempotency_key' => $payload['idempotency_key'],
                'user_id' => $payload['user_id'],
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return true;
    }

    private function assertBillingNotification(Notification $notification): void
    {
        if (! $notification instanceof BillingNotificationContract) {
            throw new \InvalidArgumentException(
                'Notification '.$notification::class.' debe implementar BillingNotificationContract.'
            );
        }
    }
}

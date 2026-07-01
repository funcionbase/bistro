<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Jobs\SendOrderStatusSmsJob;
use App\Models\Order;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registra (idempotente, N-instance-safe) la intención de notificar al
 * cliente por SMS un cambio de estado de orden (#275) y despacha el job
 * de envío. Extraído de `OrderController` para que cualquier código que
 * mute `orders.status` a un estado notificable (KDS, cierre de sesión QR,
 * sync offline) pueda disparar el mismo SMS — antes solo lo hacían
 * `updateStatus`/`closeWithPayment`, dejando fuera los caminos más
 * comunes de cierre de orden.
 *
 * Debe invocarse SIEMPRE fuera de la transacción que muta el estado de la
 * orden, para que ningún fallo del SMS pueda abortar/revertir la mutación
 * de negocio ya commiteada (CLAUDE.md §12/§13).
 */
class OrderStatusSmsDispatcher
{
    public function dispatch(Order $order, string $toStatus, ?User $user = null): void
    {
        try {
            $intentId = $this->recordIntent($order, $toStatus, $user);
            if ($intentId !== null) {
                SendOrderStatusSmsJob::dispatch($intentId);
            }
        } catch (\Throwable $e) {
            // El SMS es best-effort: si el registro/encolado falla, lo dejamos
            // logueado y seguimos. El cambio de estado YA commiteó.
            Log::channel('single')->warning('order.sms.dispatch_failed', [
                'order_id' => $order->id,
                'to_status' => $toStatus,
                'error' => $e->getMessage(),
            ]);
            // Limpiar la notificación huérfana: el insert ya ocurrió pero el
            // dispatch falló → marcar failed para no dejarla atascada en 'queued'.
            if (isset($intentId) && $intentId !== null) {
                DB::table('order_sms_notifications')
                    ->where('id', $intentId)
                    ->where('status', 'queued')
                    ->update(['status' => 'failed', 'error' => 'dispatch failed: '.$e->getMessage(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * Guarda `user_id` (quien disparó) para poder avisarle si el envío async
     * termina en `failed`. No registra si: el estado no es notificable (config),
     * la orden no tiene teléfono, o el teléfono es inválido (se loguea el
     * motivo, sin romper el flujo de la orden).
     */
    private function recordIntent(Order $order, string $toStatus, ?User $user): ?string
    {
        $notifiable = (array) config('order_notifications.sms_statuses', []);
        if (! in_array($toStatus, $notifiable, true)) {
            return null;
        }

        $rawPhone = trim((string) ($order->client_phone ?? ''));
        if ($rawPhone === '') {
            return null;
        }

        $e164 = PhoneNumber::toE164($rawPhone);
        if ($e164 === null) {
            Log::channel('single')->info('order.sms.skipped_invalid_phone', [
                'order_id' => $order->id,
                'to_status' => $toStatus,
                'phone_masked' => PhoneNumber::mask($rawPhone),
            ]);

            return null;
        }

        $id = (string) Str::orderedUuid();
        $now = now();

        // insertOrIgnore → ON CONFLICT DO NOTHING. Devuelve 1 si insertó, 0 si
        // ya existía (otra instancia/reintento ganó la carrera). Solo el que
        // inserta despacha el job.
        $inserted = DB::table('order_sms_notifications')->insertOrIgnore([
            'id' => $id,
            'order_id' => $order->id,
            'company_nit' => $order->company_nit,
            'branch_id' => $order->branch_id,
            'to_status' => $toStatus,
            'phone' => $e164,
            'user_id' => $user?->id,
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $inserted === 1 ? $id : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderSmsNotification;
use App\Models\Scopes\BranchScope;
use App\Services\AuditService;
use App\Services\Sms\SmsChatLogger;
use App\Services\Sms\SnsSmsSender;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Publica a Amazon SNS el SMS al cliente por un cambio de estado de orden (#275).
 *
 * Recibe el id de un `OrderSmsNotification` ya insertado (status='queued')
 * dentro de la transacción + lockForUpdate del cambio de estado. Ese registro,
 * con su UNIQUE(order_id, to_status), es el ancla de deduplicación N-instance:
 *
 *  - El job se despacha con afterCommit (solo si la transacción commiteó).
 *  - Guard de idempotencia: si el registro ya no está en 'queued', salimos
 *    (otra ejecución/reintento ya lo resolvió).
 *  - ShouldBeUnique (por notificationId) evita encolar el mismo job dos veces;
 *    la garantía dura es el registro único + el guard, no el lock de cache.
 *
 * Nunca rompe el flujo de la orden: cualquier fallo se registra como
 * `failed` + auditoría `order.sms_failed`, sin reventar la transacción de
 * negocio (que ya commiteó antes de este job).
 */
class SendOrderStatusSmsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** @var string */
    public $queue = 'notifications';

    /** @var int */
    public $tries = 3;

    /** @var int */
    public $backoff = 30;

    public function __construct(public string $notificationId) {}

    public function uniqueId(): string
    {
        return $this->notificationId;
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(SnsSmsSender $sender, AuditService $audit, SmsChatLogger $chatLogger): void
    {
        /** @var OrderSmsNotification|null $notification */
        $notification = OrderSmsNotification::query()
            ->withoutGlobalScope(BranchScope::class)
            ->find($this->notificationId);

        // Guard de idempotencia: solo procesamos pendientes.
        if ($notification === null || $notification->status !== 'queued') {
            return;
        }

        // En local/qa el master switch viene apagado: registramos skipped para
        // no gastar saldo SNS ni ensuciar los contadores de enviados/fallidos.
        if (! $sender->isEnabled()) {
            $notification->status = 'skipped';
            $notification->save();
            Log::channel('single')->info('order.sms.skipped_disabled', [
                'notification_id' => $notification->id,
                'order_id' => $notification->order_id,
                'to_status' => $notification->to_status,
            ]);

            return;
        }

        /** @var Order|null $order */
        $order = Order::withoutBranchScope()->with('company')->find($notification->order_id);
        if ($order === null) {
            $this->markFailed($notification, $audit, 'order_not_found');

            return;
        }

        $message = $this->buildMessage($order, $notification->to_status);
        $result = $sender->send($notification->phone, $message);

        // Visibilidad por cliente (Fase 2): el SMS queda en el hilo del cliente
        // como mensaje saliente, tanto si se envió como si falló.
        $notification->chat_message_id = $chatLogger->record(
            $order,
            $message,
            $result->sent ? 'sent' : 'failed',
        );

        if (! $result->sent) {
            $this->markFailed($notification, $audit, $result->error ?? 'sns_publish_failed');

            return;
        }

        $notification->status = 'sent';
        $notification->provider_message_id = $result->messageId;
        $notification->segments = $result->segments;
        $notification->sent_at = now();
        $notification->save();

        $audit->log('order.sms_sent', null, $notification, [
            'order_id' => $notification->order_id,
            'to_status' => $notification->to_status,
            'phone' => PhoneNumber::mask($notification->phone),
            'provider' => 'sns',
            'provider_message_id' => $result->messageId,
            'segments' => $result->segments,
        ]);
    }

    /**
     * Compone el cuerpo del SMS: nombre comercial (truncado) + código corto de
     * la orden + frase del estado en lenguaje cliente. El texto se transifere a
     * ASCII en el sender para mantener 1 segmento GSM-7.
     */
    private function buildMessage(Order $order, string $toStatus): string
    {
        $brand = (string) ($order->company?->commercial_name ?? config('app.name'));
        $max = (int) config('order_notifications.brand_max_chars', 24);
        $brand = Str::limit(trim($brand), $max, '...');

        $phrase = (string) config(
            'order_notifications.sms_phrases.'.$toStatus,
            config('orders.labels.'.$toStatus, $toStatus),
        );

        $template = (string) config('order_notifications.sms_template', ':brand: tu pedido #:code :phrase');

        return strtr($template, [
            ':brand' => $brand,
            ':code' => $order->shortCode(),
            ':phrase' => $phrase,
        ]);
    }

    private function markFailed(OrderSmsNotification $notification, AuditService $audit, string $error): void
    {
        $notification->status = 'failed';
        $notification->error = Str::limit($error, 480, '');
        $notification->save();

        $audit->log('order.sms_failed', null, $notification, [
            'order_id' => $notification->order_id,
            'to_status' => $notification->to_status,
            'phone' => PhoneNumber::mask($notification->phone),
            'provider' => 'sns',
            'error' => $notification->error,
        ]);
    }
}

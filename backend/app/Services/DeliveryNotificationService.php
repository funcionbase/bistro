<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Envía notificaciones WhatsApp al cliente durante el ciclo de vida de un domicilio.
 *
 * Eventos que notifica: asignación de repartidor, entrega completada y reasignación.
 * Si delivery.whatsapp_api_key no está configurado, los mensajes se registran en Log (modo stub).
 * Las notificaciones se omiten si el pedido no tiene client_phone registrado.
 * Activación por evento configurable en config/delivery.php:
 * notify_on_assignment, notify_on_completion, share_courier_phone.
 */
class DeliveryNotificationService
{
    public function notifyClientAssignment(Order $order, Delivery $delivery): void
    {
        if (! config('delivery.notify_on_assignment', true)) {
            return;
        }

        if (! $order->client_phone) {
            return;
        }

        $deliverer = $delivery->deliverer;
        $courierName = $deliverer?->name ?? 'un repartidor';

        $message = "Hola, tu pedido #{$order->id} ha sido asignado a {$courierName}.";

        if (config('delivery.share_courier_phone', true) && $deliverer?->phone) {
            $message .= " Teléfono: {$deliverer->phone}.";
        }

        $this->sendWhatsApp($order->client_phone, $message);
    }

    public function notifyClientDelivered(Order $order, Delivery $delivery): void
    {
        if (! config('delivery.notify_on_completion', true)) {
            return;
        }

        if (! $order->client_phone) {
            return;
        }

        $duration = $delivery->duration_minutes;
        $message = "¡Tu pedido #{$order->id} ha sido entregado!";

        if ($duration !== null) {
            $message .= " Tiempo de entrega: {$duration} minutos.";
        }

        $message .= ' ¡Gracias por tu compra!';

        $this->sendWhatsApp($order->client_phone, $message);
    }

    public function notifyClientReassignment(Order $order, Delivery $newDelivery): void
    {
        if (! $order->client_phone) {
            return;
        }

        $deliverer = $newDelivery->deliverer;
        $courierName = $deliverer?->name ?? 'un nuevo repartidor';

        $message = "Tu pedido #{$order->id} ha sido reasignado a {$courierName}.";

        if (config('delivery.share_courier_phone', true) && $deliverer?->phone) {
            $message .= " Teléfono: {$deliverer->phone}.";
        }

        $this->sendWhatsApp($order->client_phone, $message);
    }

    private function sendWhatsApp(string $phone, string $message): void
    {
        try {
            $apiKey = config('delivery.whatsapp_api_key');

            if (! $apiKey) {
                Log::info('[WhatsApp stub] Notification', ['phone' => $phone, 'message' => $message]);

                return;
            }

            // TODO: Integrate with real WhatsApp Business API (Twilio / Meta)
            Log::info('[WhatsApp] Sending notification', ['phone' => $phone, 'message' => $message]);
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] notification failed (non-blocking)', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

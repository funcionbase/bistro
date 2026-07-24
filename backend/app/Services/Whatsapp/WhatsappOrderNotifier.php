<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Order;
use App\Services\CrmService;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp como canal PRIMARIO de la notificación de cambio de estado de orden;
 * el SMS es el respaldo (lo decide `SendOrderStatusSmsJob`, que llama a este
 * servicio primero y cae a SMS si devuelve null).
 *
 * Regla de disponibilidad (segura contra baneo): solo se notifica por WhatsApp
 * si el cliente escribió por ese canal dentro de la ventana iniciada por él
 * (`whatsapp_window_hours`, default 24h) Y el canal Evolution está conectado.
 * Baileys es una sesión personal sin plantillas aprobadas: mandar un WhatsApp
 * proactivo a un número que no escribió recientemente arriesga el baneo del
 * número. Fuera de la ventana → SMS.
 *
 * Se ejecuta en contexto de cola (sin request/JWT), por eso las queries escapan
 * BranchScope explícitamente (el scope de empresa lo da `company_nit`).
 */
class WhatsappOrderNotifier
{
    /**
     * Intenta notificar por WhatsApp. Devuelve el `ChatMessage` enviado, o null
     * si WhatsApp no está disponible para este cliente (→ el caller usa SMS).
     *
     * @param  string  $phoneE164  Teléfono del cliente en E.164 (viene de la notificación SNS).
     */
    public function notify(Order $order, string $phoneE164, string $body): ?ChatMessage
    {
        if (! (bool) config('order_notifications.whatsapp_primary', true)) {
            return null;
        }

        $windowHours = max(1, (int) config('order_notifications.whatsapp_window_hours', 24));

        // El chat guarda el teléfono en el canónico `57...` (sin `+`), no en E.164:
        // se normaliza el número de la notificación para que el match funcione.
        $canonicalPhone = CrmService::normalizePhone($phoneE164);

        // Conversación de WhatsApp del cliente (source != 'sms' excluye el hilo
        // espejo que crea SmsChatLogger) con un mensaje ENTRANTE reciente: prueba
        // que está en WhatsApp y dentro de la ventana que abrió él.
        $chat = Chat::withoutBranchScope()
            ->where('company_nit', $order->company_nit)
            ->where('client_phone', $canonicalPhone)
            ->where('source', '!=', 'sms')
            ->whereHas('messages', function ($q) use ($windowHours) {
                $q->where('sender', 'client')
                    ->where('sent_at', '>=', now()->subHours($windowHours));
            })
            ->latest('last_message_at')
            ->first();

        if ($chat === null) {
            return null;
        }

        $account = $chat->resolveWhatsappChannel();
        if ($account === null || ! $account->canSendViaEvolution()) {
            return null;
        }

        // El envío requiere un ChatMessage: además de ser el vehículo del sender,
        // deja la notificación visible en el hilo del cliente (igual que el SMS
        // vía SmsChatLogger). sender='bot' respeta el CHECK de chat_messages.
        $message = new ChatMessage;
        $message->chat_id = $chat->id;
        $message->sender = 'bot';
        $message->body = $body;
        $message->status = 'sent'; // provisional: deliver() lo ajusta a sent|failed
        $message->sent_at = now();
        $message->save();

        // El sender se construye con el factory (como el resto de callers): su
        // constructor no es auto-wireable por el contenedor (depende de
        // MetaGraphApiClient::forCurrentEnvironment).
        $sender = WhatsappOutboundMessageSender::forCurrentEnvironment();

        // deliver() persiste el status real y el motivo si falla. Si no salió,
        // devolvemos null para que el caller use el SMS de respaldo; la burbuja
        // queda en 'failed' como registro del intento (simétrico al SMS fallido).
        if (! $sender->deliver($chat, $message)) {
            Log::channel('single')->info('order.whatsapp.deliver_failed_fallback_sms', [
                'order_id' => $order->id,
                'chat_id' => $chat->id,
                'reason' => $message->failure_reason,
            ]);

            return null;
        }

        $chat->last_message_at = now();
        $chat->save();

        return $message;
    }
}

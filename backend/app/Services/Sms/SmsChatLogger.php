<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Order;
use App\Services\CrmService;

/**
 * Persiste cada SMS enviado al cliente como un `ChatMessage` saliente dentro de
 * un `Chat` con `source='sms'` (Fase 2), para que aparezca en el hilo del
 * cliente (`/clients/{id}` vía `CrmService::profile`) y en `/chats`.
 *
 * Clave canónica del chat = `contact.phone` cuando la orden tiene contacto
 * identificado (así `CrmService::profile`, que matchea `Chat.client_phone =
 * contact.phone`, lo encuentra exacto). Para órdenes anónimas cae al teléfono
 * normalizado de la orden (`CrmService::normalizePhone`).
 *
 * No cambia el schema: `sender='bot'` respeta el CHECK de `chat_messages` y
 * `source='sms'` es libre en `chats.source` (sin CHECK).
 *
 * Se invoca desde `SendOrderStatusSmsJob` (contexto de cola, sin request) — por
 * eso todas las queries escapan `BranchScope` explícitamente.
 */
class SmsChatLogger
{
    /**
     * Registra el SMS en el hilo del cliente. Devuelve el id del ChatMessage
     * creado, o null si no se pudo resolver un teléfono canónico.
     *
     * @param  'sent'|'failed'  $status
     */
    public function record(Order $order, string $body, string $status): ?string
    {
        $contact = $order->contact;

        $clientPhone = $contact?->phone;
        if ($clientPhone === null || $clientPhone === '') {
            $clientPhone = CrmService::normalizePhone((string) ($order->client_phone ?? ''));
        }

        if ($clientPhone === '') {
            return null;
        }

        $chat = Chat::withoutBranchScope()
            ->where('company_nit', $order->company_nit)
            ->where('client_phone', $clientPhone)
            ->where('source', 'sms')
            ->first();

        if ($chat === null) {
            $chat = new Chat;
            $chat->company_nit = $order->company_nit;
            $chat->branch_id = $order->branch_id;
            $chat->client_phone = $clientPhone;
            $chat->client_name = $contact?->name;
            $chat->contact_id = $contact?->id;
            $chat->source = 'sms';
            $chat->status = 'open';
            // No hay bot conversacional para SMS: el hilo nace pausado para que
            // ningún flujo de auto-respuesta intente contestar un SMS saliente.
            $chat->bot_paused = true;
        } else {
            // Backfill de identidad si el contacto se identificó después de
            // crear el hilo (orden anónima → luego asociada a un Contact).
            if ($chat->contact_id === null && $contact !== null) {
                $chat->contact_id = $contact->id;
            }
            if (($chat->client_name === null || $chat->client_name === '') && $contact?->name) {
                $chat->client_name = $contact->name;
            }
        }

        $chat->last_message_at = now();
        $chat->save();

        $message = new ChatMessage;
        $message->chat_id = $chat->id;
        $message->sender = 'bot';
        $message->status = $status;
        $message->body = $body;
        $message->sent_at = now();
        $message->save();

        return $message->id;
    }
}

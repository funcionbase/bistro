<?php

namespace App\Services\Whatsapp;

use App\Jobs\DownloadWhatsappMediaJob;
use App\Models\Branch;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\CompanyWhatsappAccount;
use App\Models\CompanyWhatsappAccountEvent;
use App\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Convierte el payload de webhook de WhatsApp Cloud API en filas de chats /
 * chat_messages. Maneja unicamente mensajes entrantes (cliente → empresa).
 *
 * MIENTRAS n8n NO ESTE DISPONIBLE: el bot esta efectivamente apagado. Por eso
 * marcamos cada conversacion nueva con bot_paused=true. El operador atiende
 * manualmente desde el panel de chats. Cuando n8n este listo, este flag se
 * apagara explicitamente desde el flujo del bot.
 *
 * Resolucion phone_number_id → company:
 *   payload.entry[].changes[].value.metadata.phone_number_id  →
 *   company_whatsapp_accounts.phone_number_id  →  company_nit
 *
 * Si no encuentra la empresa, registra y descarta. Asi un cliente que aun no
 * conecta su numero no recibe mensajes "fantasma".
 */
class WhatsappInboundMessageHandler
{
    public function handle(array $payload): array
    {
        $stats = ['messages_processed' => 0, 'messages_skipped' => 0, 'unknown_phone' => 0, 'statuses_processed' => 0];

        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return $stats;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                if (! $phoneNumberId) {
                    $stats['messages_skipped']++;

                    continue;
                }

                $account = CompanyWhatsappAccount::query()
                    ->where('phone_number_id', $phoneNumberId)
                    ->first();

                if ($account === null) {
                    $stats['unknown_phone']++;
                    Log::channel('single')->warning('whatsapp.webhook.unknown_phone', [
                        'phone_number_id' => $phoneNumberId,
                    ]);

                    continue;
                }

                $contactsByWaId = [];
                foreach ($value['contacts'] ?? [] as $contact) {
                    if (isset($contact['wa_id'])) {
                        $contactsByWaId[$contact['wa_id']] = $contact['profile']['name'] ?? null;
                    }
                }

                foreach ($value['messages'] ?? [] as $message) {
                    if ($this->persistInboundMessage($account, $message, $contactsByWaId)) {
                        $stats['messages_processed']++;
                    } else {
                        $stats['messages_skipped']++;
                    }
                }

                // Status updates de mensajes salientes (operador/bot -> cliente):
                // Meta nos avisa cuando el cliente recibe/lee/falla el mensaje.
                // Mapeamos el wamid al ChatMessage local y actualizamos status.
                foreach ($value['statuses'] ?? [] as $status) {
                    if ($this->applyOutboundStatus($account, $status)) {
                        $stats['statuses_processed']++;
                    }
                }
            }
        }

        return $stats;
    }

    private function persistInboundMessage(
        CompanyWhatsappAccount $account,
        array $message,
        array $contactsByWaId,
    ): bool {
        $content = $this->extractContent($message);

        if ($content === null) {
            return false;
        }

        $waId = (string) ($message['from'] ?? '');
        if ($waId === '') {
            return false;
        }

        $clientPhone = '+'.ltrim($waId, '+');
        $clientName = $contactsByWaId[$waId] ?? null;
        $metaMessageId = $message['id'] ?? null;
        // Meta envia timestamp como Unix epoch (UTC). Lo creamos en zona de la
        // app para que Eloquent serialice Bogota wall-clock y la sesion de PG
        // (que setea SET TIME ZONE America/Bogota) lo guarde correcto.
        $sentAt = isset($message['timestamp'])
            ? Carbon::createFromTimestamp((int) $message['timestamp'], config('app.timezone'))
            : now();

        $createdMessage = null;

        DB::transaction(function () use ($account, $clientPhone, $clientName, $content, $metaMessageId, $sentAt, &$createdMessage): void {
            // Multi-sede (#117, #192): el chat es único por (company_nit,
            // client_phone) — su `branch_id` indica la sede que LO ATIENDE.
            // Al recibir un mensaje de un teléfono SIN chat previo, el chat
            // se crea asignado a la sede `is_default=true` de la empresa
            // (decisión documentada en #192: el cliente final no decide
            // sede; el operador puede reasignar después).
            // Al recibir un mensaje sobre un chat existente, NO se rebota
            // su `branch_id` — la asignación es manual vía
            // `POST /api/v1/chats/{chat}/reassign-branch` (permiso
            // `chats.reassign_branch` o ser owner).
            $defaultBranchId = Branch::query()
                ->where('company_nit', $account->company_nit)
                ->whereNull('archived_at')
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->value('id');

            $contact = Contact::firstOrCreate(
                ['company_nit' => $account->company_nit, 'phone' => $clientPhone],
                ['name' => $clientName, 'branch_id' => $defaultBranchId],
            );

            /** @var Chat $chat */
            $chat = Chat::firstOrNew([
                'company_nit' => $account->company_nit,
                'client_phone' => $clientPhone,
            ]);

            if (! $chat->exists) {
                $chat->status = 'open';
                $chat->branch_id = $defaultBranchId;
                // n8n no disponible: bot apagado. El operador atiende manualmente.
                $chat->bot_paused = true;
                $chat->client_name = $clientName ?? $contact->name;
                $chat->source = 'whatsapp';
            }

            $chat->contact_id = $contact->id;
            $chat->last_message_at = $sentAt;
            $chat->meta_synced_at = now();
            $chat->save();

            if ($metaMessageId !== null) {
                $existing = ChatMessage::query()
                    ->where('chat_id', $chat->id)
                    ->where('meta_message_id', $metaMessageId)
                    ->first();

                if ($existing !== null) {
                    return;
                }
            }

            $createdMessage = ChatMessage::create([
                'chat_id' => $chat->id,
                'sender' => 'client',
                'status' => null,
                'body' => $content['body'],
                'meta_message_id' => $metaMessageId,
                'media_type' => $content['media_type'],
                'media_meta_id' => $content['media_meta_id'],
                'media_mime' => $content['media_mime'],
                'sent_at' => $sentAt,
            ]);
        });

        // Dispara la descarga del media en cola si aplica. Fuera de la
        // transaccion para que el job no se enqueue con un id que aun no esta
        // visible para el worker (race con la BD).
        if ($createdMessage !== null && ! empty($content['media_meta_id'])) {
            DownloadWhatsappMediaJob::dispatch($createdMessage->id);
        }

        // El read-receipt (doble chulito azul) ya NO se dispara aqui. Se dispara
        // cuando el operador abre el chat con la pestana visible — ver endpoint
        // POST /api/v1/chats/{id}/mark-read en `ChatController::markRead`.

        CompanyWhatsappAccountEvent::create([
            'company_whatsapp_account_id' => $account->id,
            'event_type' => 'message_received',
            'payload' => [
                'wa_id' => $waId,
                'meta_message_id' => $metaMessageId,
                'type' => $message['type'] ?? 'text',
            ],
            'created_at' => now(),
        ]);

        return true;
    }

    /**
     * Extrae body + media del mensaje entrante segun su tipo. Devuelve null si
     * el tipo no es soportado / no se pudo parsear.
     *
     * @return array{body: string, media_type: ?string, media_meta_id: ?string, media_mime: ?string}|null
     */
    private function extractContent(array $message): ?array
    {
        $type = $message['type'] ?? 'text';

        return match ($type) {
            'text' => [
                'body' => $message['text']['body'] ?? '',
                'media_type' => null,
                'media_meta_id' => null,
                'media_mime' => null,
            ],
            'button' => [
                'body' => $message['button']['text'] ?? '',
                'media_type' => null,
                'media_meta_id' => null,
                'media_mime' => null,
            ],
            'interactive' => [
                'body' => $message['interactive']['button_reply']['title']
                    ?? $message['interactive']['list_reply']['title']
                    ?? '',
                'media_type' => null,
                'media_meta_id' => null,
                'media_mime' => null,
            ],
            'sticker' => [
                'body' => '[sticker]',
                'media_type' => 'sticker',
                'media_meta_id' => $message['sticker']['id'] ?? null,
                'media_mime' => $message['sticker']['mime_type'] ?? 'image/webp',
            ],
            'image', 'video', 'audio', 'document' => [
                'body' => '['.$type.']'.(isset($message[$type]['caption']) ? ' '.$message[$type]['caption'] : ''),
                'media_type' => $type,
                'media_meta_id' => $message[$type]['id'] ?? null,
                'media_mime' => $message[$type]['mime_type'] ?? null,
            ],
            'location' => [
                'body' => $this->formatLocationBody($message['location'] ?? []),
                'media_type' => null,
                'media_meta_id' => null,
                'media_mime' => null,
            ],
            default => null,
        };
    }

    /**
     * Aplica un status update de un mensaje saliente (operador/bot -> cliente).
     * Meta envia uno o varios eventos por wamid: sent -> delivered -> read.
     *
     * Solo escalamos hacia adelante (sent < delivered < read). Si llega un evento
     * mas viejo despues de uno nuevo (raro pero posible), no lo aplicamos para
     * evitar regresiones visuales en el panel.
     */
    private function applyOutboundStatus(CompanyWhatsappAccount $account, array $status): bool
    {
        $wamid = $status['id'] ?? null;
        $newStatus = $status['status'] ?? null;

        if ($wamid === null || $newStatus === null) {
            return false;
        }

        // El check constraint de chat_messages.status acepta: sent, delivered, read, failed.
        if (! in_array($newStatus, ['sent', 'delivered', 'read', 'failed'], true)) {
            return false;
        }

        $message = ChatMessage::query()
            ->where('meta_message_id', $wamid)
            ->whereHas('chat', fn ($q) => $q->where('company_nit', $account->company_nit))
            ->first();

        if ($message === null) {
            // Status para un wamid que no enviamos nosotros (o que no persistimos).
            // Comun cuando el bot externo manda mensajes — ignorar.
            return false;
        }

        // `failed` es TERMINAL: una vez fallido no se degrada a sent/delivered/
        // read aunque Meta entregue un callback tardío fuera de orden (sucede).
        // Sin esta guarda, un `delivered`(rank 2)/`read`(rank 3) tardío pasaba
        // `newRank <= currentRank(0)` = false y sobreescribía el `failed`.
        if ($message->status === 'failed') {
            return false;
        }

        // Orden monotonico: no degradar de read -> delivered, etc.
        $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 0];
        $currentRank = $rank[$message->status] ?? 0;
        $newRank = $rank[$newStatus] ?? 0;

        // failed siempre gana (override) para que el operador vea el error.
        if ($newStatus !== 'failed' && $newRank <= $currentRank) {
            return false;
        }

        $message->forceFill(['status' => $newStatus])->save();

        return true;
    }

    /**
     * Formato compacto del body para una location: "[location] lat, lng | nombre | direccion".
     * El frontend parsea solo lat/lng del prefijo y muestra el resto si esta presente.
     */
    private function formatLocationBody(array $location): string
    {
        $lat = $location['latitude'] ?? '?';
        $lng = $location['longitude'] ?? '?';
        $extras = array_filter([
            $location['name'] ?? null,
            $location['address'] ?? null,
        ]);
        $body = sprintf('[location] %s, %s', $lat, $lng);

        if (! empty($extras)) {
            $body .= ' | '.implode(' | ', $extras);
        }

        return $body;
    }
}

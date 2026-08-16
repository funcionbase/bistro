<?php

namespace App\Services\Whatsapp;

use App\Jobs\DownloadWhatsappMediaJob;
use App\Jobs\SendAwayReplyJob;
use App\Jobs\SendChatInboundPushJob;
use App\Models\Branch;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\CompanyWhatsappAccount;
use App\Models\CompanyWhatsappAccountEvent;
use App\Models\Contact;
use App\Services\CrmService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

        // Meta envia timestamp como Unix epoch (UTC). Lo creamos en zona de la
        // app para que Eloquent serialice Bogota wall-clock y la sesion de PG
        // (que setea SET TIME ZONE America/Bogota) lo guarde correcto.
        $sentAt = isset($message['timestamp'])
            ? Carbon::createFromTimestamp((int) $message['timestamp'], config('app.timezone'))
            : now();

        return $this->persistInbound($account, new NormalizedInboundMessage(
            clientPhoneE164: '+'.ltrim($waId, '+'),
            clientName: $contactsByWaId[$waId] ?? null,
            body: $content['body'],
            providerMessageId: $message['id'] ?? null,
            sentAt: $sentAt,
            fromMe: false,
            mediaType: $content['media_type'],
            // Meta no manda el binario: se descarga despues contra Graph.
            mediaProviderId: $content['media_meta_id'],
            mediaMime: $content['media_mime'],
        )) !== null;
    }

    /**
     * Persiste un mensaje entrante ya normalizado. Es el UNICO lugar donde se
     * resuelve la sede, se crea el contacto, se abre o adopta el chat y se
     * escribe la fila — Meta y Evolution lo alimentan por igual (§6.4).
     *
     * Devuelve null si el mensaje se descarto por idempotencia (ya existia).
     */
    public function persistInbound(CompanyWhatsappAccount $account, NormalizedInboundMessage $msg): ?ChatMessage
    {
        // Canónico de almacenamiento (`57XXXXXXXXXX`, sin `+`): el webhook entrega
        // E.164 pero contacts/chats se guardan y se buscan en este formato. Sin
        // normalizar aquí, el firstOrNew/where de abajo no matchea el contacto ni
        // el chat del CRM/SMS y crea un duplicado del mismo cliente. (Los mutators
        // normalizan el SET, pero el WHERE del lookup usa este valor tal cual.)
        $clientPhone = CrmService::normalizePhone($msg->clientPhoneE164);
        $clientName = $msg->clientName;
        $metaMessageId = $msg->providerMessageId;
        $sentAt = $msg->sentAt;

        $createdMessage = null;
        $contactWasCreated = false;

        DB::transaction(function () use ($account, $msg, $clientPhone, $clientName, $metaMessageId, $sentAt, &$createdMessage, &$contactWasCreated): void {
            // Multi-sede + multi-canal (F1): la sede del chat la
            // decide EL CANAL por el que entró el mensaje. Un canal de sede
            // (`branch_id` no nulo) hace nacer el chat en esa sede; un canal de
            // empresa (`branch_id` nulo) cae al comportamiento anterior — sede
            // `is_default=true` y reasignación manual.
            // Al recibir un mensaje sobre un chat existente, NO se rebota
            // su `branch_id` — la asignación es manual vía
            // `POST /api/v1/chats/{chat}/reassign-branch` (permiso
            // `chats.reassign_branch` o ser owner).
            $branchId = $account->branch_id ?? Branch::query()
                ->where('company_nit', $account->company_nit)
                ->whereNull('archived_at')
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->value('id');

            // Homologación con el CRM en AMBOS sentidos: un cliente cargado en
            // /clients con el teléfono en otro formato (sin +57, o con 57 pegado)
            // es la MISMA persona. Se busca por variantes normalizadas —la misma
            // idea que usa CrmService para casar órdenes— para no crear un
            // duplicado del contacto que ya existe.
            //
            // `branch_id` NO es fillable en Contact (el trait BelongsToBranch lo
            // resuelve desde `active_branch_id`, que en un webhook no existe) y la
            // columna es NOT NULL, así que en el alta se fuerza explícitamente.
            $contact = Contact::withoutBranchScope()
                ->where('company_nit', $account->company_nit)
                ->whereIn('phone', $this->phoneVariants($clientPhone))
                ->first();

            if ($contact === null) {
                $contact = new Contact;
                $contact->forceFill([
                    'company_nit' => $account->company_nit,
                    'phone' => $clientPhone,
                    'name' => $clientName,
                    'branch_id' => $branchId,
                ])->save();
                $contactWasCreated = true;
            }

            // El chat es único por (canal, client_phone): el mismo cliente
            // escribiendo al número de la empresa y al de una sede son dos
            // conversaciones distintas — son interlocutores distintos.
            /** @var Chat $chat */
            $chat = Chat::firstOrNew([
                'whatsapp_account_id' => $account->id,
                'client_phone' => $clientPhone,
            ]);

            // Adopción del chat legacy: los creados por el bot externo antes de
            // conectar un canal quedaron con `whatsapp_account_id` nulo. Sin
            // esto el mismo cliente aparecería dos veces en la bandeja y la
            // duplicidad sería permanente y silenciosa.
            // `withoutBranchScope()`: flujo de webhook sin JWT — no hay
            // `active_branch_id` y el chat legacy puede estar en cualquier sede
            // de la empresa. El scope de empresa lo da el `where company_nit`.
            if (! $chat->exists) {
                $legacy = Chat::withoutBranchScope()
                    ->where('company_nit', $account->company_nit)
                    ->where('client_phone', $clientPhone)
                    ->whereNull('whatsapp_account_id')
                    ->first();

                if ($legacy !== null) {
                    $chat = $legacy;
                    $chat->whatsapp_account_id = $account->id;
                }
            }

            if (! $chat->exists) {
                $chat->company_nit = $account->company_nit;
                $chat->status = 'open';
                $chat->branch_id = $branchId;
                // n8n no disponible: bot apagado. El operador atiende manualmente.
                $chat->bot_paused = true;
                $chat->client_name = $clientName ?? $contact->name;
                $chat->source = 'whatsapp';
            }

            $chat->contact_id = $contact->id;
            $chat->last_message_at = $sentAt;

            if ($msg->fromMe) {
                // El dueño respondio desde SU celular: el cliente ya no espera.
                $chat->pending_reply_since = null;
            } elseif ($chat->pending_reply_since === null) {
                // Marca el INICIO de la espera, no el último mensaje: si ya
                // estaba esperando, el reloj no se reinicia con cada mensaje.
                $chat->pending_reply_since = $sentAt;
            }

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
                // `fromMe` = lo mando el dueño desde su celular. Es un mensaje
                // saliente sin usuario del panel detras: `sent_by_user_id` queda
                // null y la UI lo rotula "desde el celular" (§5.7).
                'sender' => $msg->fromMe ? 'operator' : 'client',
                'sent_by_user_id' => null,
                // Marca explicita en vez de deducirla de (operator + sin autor):
                // esa combinacion tambien matchea todos los mensajes anteriores
                // a F1 y los rotularia "desde el celular" siendo falso.
                'from_device' => $msg->fromMe,
                'status' => $msg->fromMe ? 'sent' : null,
                'body' => $msg->body,
                'meta_message_id' => $metaMessageId,
                'media_type' => $msg->mediaType,
                'media_meta_id' => $msg->mediaProviderId,
                'media_mime' => $msg->mediaMime,
                'media_payload' => $msg->mediaPayload,
                'sent_at' => $sentAt,
            ]);
        });

        // Homologación con el CRM: un contacto nuevo creado por un mensaje
        // entrante tiene que aparecer YA en /clients. El listado del CRM está
        // cacheado (`crm:list:base:{nit}`) y persistInbound no lo invalidaba, así
        // que el contacto recién creado no se veía hasta que expiraba el cache.
        // Contacto y cliente del CRM son la misma tabla; esto los mantiene en sync.
        if ($contactWasCreated) {
            app(CrmService::class)->forgetCache((string) $account->company_nit);
        }

        if ($createdMessage !== null) {
            $this->attachMedia($createdMessage, $msg);

            // Aviso al operador (§8.4b punto 1). Solo lo del cliente: lo que el
            // dueño manda desde su celular no es nada que atender. Va a la cola
            // para que la latencia del push no se sume a la del webhook —
            // Evolution reintenta si tardamos.
            if (! $msg->fromMe) {
                SendChatInboundPushJob::dispatch((string) $createdMessage->id);
                // Respuesta automatica fuera de horario (§8.4b punto 10). El job
                // decide si corresponde (setting + horario + dedupe); aca solo se
                // encola para no sumar su latencia a la del webhook.
                SendAwayReplyJob::dispatch((string) $createdMessage->chat_id);

                // F6 (§9.2): push a n8n del mensaje entrante. El dispatcher decide
                // si hay flujo habilitado y suscrito; si no, no emite. Esto es
                // sender='client' (nunca 'bot'), así que el anti-loop se cumple
                // por construcción.
                app(AutomationDispatcher::class)->forChat(
                    AutomationDispatcher::EVENT_MESSAGE_RECEIVED,
                    $createdMessage->chat,
                    $createdMessage,
                );
            }
        }

        // El read-receipt (doble chulito azul) ya NO se dispara aqui. Se dispara
        // cuando el operador abre el chat con la pestana visible — ver endpoint
        // POST /api/v1/chats/{id}/mark-read en `ChatController::markRead`.

        CompanyWhatsappAccountEvent::create([
            'company_whatsapp_account_id' => $account->id,
            'event_type' => $msg->fromMe ? 'message_sent_from_device' : 'message_received',
            'payload' => [
                'wa_id' => EvolutionClient::toMsisdn($clientPhone),
                'meta_message_id' => $metaMessageId,
                'type' => $msg->mediaType ?? 'text',
            ],
            'created_at' => now(),
        ]);

        return $createdMessage;
    }

    /**
     * Guarda el binario del mensaje segun de donde venga.
     *
     *  - Evolution lo manda EMBEBIDO (`base64: true`). Se escribe a S3 aca mismo:
     *    los bytes ya estan en RAM y no hay forma de pedirlos despues
     *    (`getBase64FromMediaMessage` lee una tabla vacia — §6.7). Encolarlos
     *    trasladaria 16 MB por mensaje a la tabla `jobs`, que es el mismo bloat
     *    que §6.3 saca de `webhook_events`.
     *  - Meta manda un id y la descarga va en cola contra Graph.
     *
     * ponytail: el PUT es sincrono dentro del webhook. Si la latencia llega a
     * molestar, la salida es encolar con la media en cache — nunca meterla en el
     * payload del job.
     */
    private function attachMedia(ChatMessage $message, NormalizedInboundMessage $msg): void
    {
        if ($msg->mediaBase64 !== null) {
            $bytes = base64_decode($msg->mediaBase64, true);

            if ($bytes === false) {
                Log::channel('single')->warning('whatsapp.media.invalid_base64', [
                    'message_id' => $message->id,
                ]);

                return;
            }

            $relativePath = sprintf(
                'chat-media/%s/%s.%s',
                $message->chat_id,
                $message->id,
                $this->extensionFor($msg->mediaMime),
            );

            Storage::disk(config('filesystems.default'))->put($relativePath, $bytes);

            $message->forceFill(['media_path' => $relativePath])->save();

            return;
        }

        // Fuera de la transaccion para que el job no se encole con un id que aun
        // no esta visible para el worker (race con la BD).
        if (! empty($msg->mediaProviderId)) {
            DownloadWhatsappMediaJob::dispatch($message->id);
        }
    }

    /**
     * ponytail: copia deliberada del mapa de `DownloadWhatsappMediaJob`. Ese job
     * se borra entero con el webhook de Meta en F4; compartir el mapa obligaria
     * a una clase nueva de la que dependerian los dos caminos por diez lineas
     * que tienen fecha de vencimiento.
     */
    private function extensionFor(?string $mime): string
    {
        // `audio/ogg; codecs=opus` → `audio/ogg`.
        $mime = $mime !== null ? trim(explode(';', $mime)[0]) : null;

        return match ($mime) {
            'image/webp' => 'webp',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'video/quicktime' => 'mov',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/aac' => 'm4a',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
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
     *
     * Publico desde F2: los acks de Evolution (`messages.update`) entran por
     * aca despues de traducir los valores de Baileys (`DELIVERY_ACK`, `READ`) a
     * los nuestros. La logica monotonica y la guarda de `failed` como terminal
     * son identicas para los dos proveedores — no se duplican.
     *
     * @param  array{id?: ?string, status?: ?string}  $status
     */
    public function applyOutboundStatus(CompanyWhatsappAccount $account, array $status): bool
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
     * Variantes del mismo número para casar un contacto entre el chat y el CRM:
     * el E.164 con `+`, sin `+`, y con/sin el indicativo 57 pegado. Un cliente
     * cargado como `3001234567` y el mismo que escribe como `+573001234567` son
     * la misma persona; sin esto se crearía un contacto duplicado por formato.
     *
     * @return list<string>
     */
    private function phoneVariants(string $e164): array
    {
        $digits = preg_replace('/\D+/', '', $e164) ?? '';
        $variants = ['+'.$digits, $digits, $e164];

        if (str_starts_with($digits, '57')) {
            $bare = substr($digits, 2);
            $variants[] = $bare;
            $variants[] = '+57'.$bare;
        } else {
            $variants[] = '57'.$digits;
            $variants[] = '+57'.$digits;
        }

        return array_values(array_unique(array_filter($variants, static fn (string $v) => $v !== '' && $v !== '+')));
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

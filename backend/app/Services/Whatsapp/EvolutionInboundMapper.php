<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Rules\SafePlainText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Traduce el `data` de un `messages.upsert` de Evolution a
 * {@see NormalizedInboundMessage} (plan 8-whatsapp.md §6.4 y §6.7).
 *
 * Cubre los 9 tipos: texto, imagen, video, audio, nota de voz, documento,
 * sticker, ubicación y contacto.
 *
 * Devuelve null cuando el mensaje se descarta a propósito (grupo, `@lid`, tipo
 * no soportado). El caller lo cuenta como `skipped`, no como error.
 *
 * BORDE DE CONFIANZA (§6.6): el `body` y el `pushName` son texto de usuario
 * final que se persiste y se renderiza. Pasan por `SafePlainText::sanitize()`
 * aunque el servidor de Evolution sea nuestro — no se salta validación por eso.
 */
class EvolutionInboundMapper
{
    private const BODY_MAX_BYTES = 4000;

    private const NAME_MAX_BYTES = 120;

    /**
     * @param  array<string, mixed>  $data  El `data` del evento, no el sobre completo.
     */
    public function map(array $data): ?NormalizedInboundMessage
    {
        $remoteJid = (string) ($data['key']['remoteJid'] ?? '');

        if ($remoteJid === '') {
            return null;
        }

        // Grupos: el modelo `chats` es 1:1 con un cliente. Un grupo no tiene
        // "el cliente" del que colgar la conversación.
        if (str_ends_with($remoteJid, '@g.us')) {
            Log::channel('single')->info('evolution.inbound.skipped_group', ['remote_jid' => $remoteJid]);

            return null;
        }

        // `@lid` es el direccionamiento nuevo de WhatsApp: oculta el teléfono.
        // Solo se ignora acá, en `upsert`. Los acks (`messages.update`) llegan
        // SIEMPRE en `@lid` y sí se procesan — se resuelven por `keyId`, que no
        // necesita el jid. Ver EvolutionWebhookController.
        if (str_contains($remoteJid, '@lid')) {
            Log::channel('single')->warning('evolution.inbound.skipped_lid', ['remote_jid' => $remoteJid]);

            return null;
        }

        $msisdn = strstr($remoteJid, '@', true);
        if ($msisdn === false || $msisdn === '') {
            return null;
        }

        $content = $this->extractContent((array) ($data['message'] ?? []), (string) ($data['messageType'] ?? ''));
        if ($content === null) {
            return null;
        }

        $fromMe = (bool) ($data['key']['fromMe'] ?? false);

        return new NormalizedInboundMessage(
            clientPhoneE164: '+'.$msisdn,
            // El pushName es el nombre que el CLIENTE eligió para sí mismo; si
            // el mensaje es nuestro (fromMe) ese nombre es el del dueño, no el
            // del cliente, y usarlo renombraría el chat con cada respuesta.
            clientName: $fromMe ? null : $this->clean((string) ($data['pushName'] ?? ''), self::NAME_MAX_BYTES),
            body: $content['body'],
            providerMessageId: $data['key']['id'] ?? null,
            sentAt: $this->timestamp($data['messageTimestamp'] ?? null),
            fromMe: $fromMe,
            mediaType: $content['media_type'],
            mediaMime: $content['media_mime'],
            mediaBase64: $content['media_base64'],
            mediaPayload: $content['media_payload'],
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{body: string, media_type: ?string, media_mime: ?string, media_base64: ?string, media_payload: ?array<string, mixed>}|null
     */
    private function extractContent(array $message, string $messageType): ?array
    {
        // El binario viaja como HERMANO del `<tipo>Message`, no anidado dentro
        // (verificado en F0). Es la única vía: el fallback no existe (§6.7).
        $base64 = is_string($message['base64'] ?? null) ? $message['base64'] : null;

        // Texto — dos formas según haya o no contexto (respuesta, link preview).
        if (isset($message['conversation'])) {
            return $this->text((string) $message['conversation']);
        }
        if (isset($message['extendedTextMessage']['text'])) {
            return $this->text((string) $message['extendedTextMessage']['text']);
        }

        if (isset($message['imageMessage'])) {
            return $this->media('image', (array) $message['imageMessage'], $base64, 'imagen');
        }
        if (isset($message['videoMessage'])) {
            return $this->media('video', (array) $message['videoMessage'], $base64, 'video');
        }
        if (isset($message['audioMessage'])) {
            $audio = (array) $message['audioMessage'];
            // `ptt` distingue la nota de voz grabada del archivo de audio
            // adjuntado. Cambia el render y el endpoint de reenvío.
            $isVoiceNote = (bool) ($audio['ptt'] ?? false);

            return $this->media('audio', $audio, $base64, $isVoiceNote ? 'nota de voz' : 'audio', [
                'ptt' => $isVoiceNote,
            ]);
        }
        if (isset($message['documentMessage'])) {
            return $this->media('document', (array) $message['documentMessage'], $base64, 'documento');
        }
        // documentWithCaptionMessage envuelve un documentMessage cuando lleva texto.
        if (isset($message['documentWithCaptionMessage']['message']['documentMessage'])) {
            return $this->media(
                'document',
                (array) $message['documentWithCaptionMessage']['message']['documentMessage'],
                $base64,
                'documento',
            );
        }
        if (isset($message['stickerMessage'])) {
            return $this->media('sticker', (array) $message['stickerMessage'], $base64, 'sticker');
        }

        if (isset($message['locationMessage'])) {
            return $this->location((array) $message['locationMessage']);
        }
        if (isset($message['contactMessage'])) {
            return $this->contacts([(array) $message['contactMessage']]);
        }
        if (isset($message['contactsArrayMessage']['contacts'])) {
            return $this->contacts((array) $message['contactsArrayMessage']['contacts']);
        }

        Log::channel('single')->info('evolution.inbound.unsupported_type', [
            'message_type' => $messageType,
            'keys' => array_keys($message),
        ]);

        return null;
    }

    /**
     * @return array{body: string, media_type: null, media_mime: null, media_base64: null, media_payload: null}
     */
    private function text(string $body): array
    {
        return [
            'body' => $this->clean($body, self::BODY_MAX_BYTES, allowWhitespace: true),
            'media_type' => null,
            'media_mime' => null,
            'media_base64' => null,
            'media_payload' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $extraPayload
     * @return array{body: string, media_type: string, media_mime: ?string, media_base64: ?string, media_payload: array<string, mixed>}
     */
    private function media(string $type, array $node, ?string $base64, string $label, array $extraPayload = []): array
    {
        $caption = $this->clean((string) ($node['caption'] ?? ''), self::BODY_MAX_BYTES, allowWhitespace: true);
        $fileName = $this->clean((string) ($node['fileName'] ?? ''), 255);

        $body = '['.$label.']';
        if ($fileName !== '') {
            $body .= ' '.$fileName;
        }
        if ($caption !== '') {
            $body .= ' '.$caption;
        }
        // Sin binario el mensaje se persiste igual: perder la media no puede
        // perder el hecho de que el cliente escribió (§6.7 opción (a)).
        if ($base64 === null) {
            $body .= ' (no disponible)';
        }

        $payload = array_filter([
            'file_name' => $fileName !== '' ? $fileName : null,
            'size_bytes' => $this->fileLength($node['fileLength'] ?? null),
            'duration_s' => isset($node['seconds']) ? (int) $node['seconds'] : null,
            'caption' => $caption !== '' ? $caption : null,
        ], static fn ($v) => $v !== null) + $extraPayload;

        return [
            'body' => $this->truncate($body, self::BODY_MAX_BYTES),
            'media_type' => $type,
            'media_mime' => isset($node['mimetype']) ? (string) $node['mimetype'] : null,
            'media_base64' => $base64,
            'media_payload' => $payload,
        ];
    }

    /**
     * `name` y `address` son OPCIONALES en la entrada: una ubicación cruda llega
     * sin ninguno de los dos (verificado en F0). Al reenviar hay que
     * sintetizarlos, porque `sendLocation` sí los exige.
     *
     * @param  array<string, mixed>  $node
     * @return array{body: string, media_type: string, media_mime: null, media_base64: null, media_payload: array<string, mixed>}
     */
    private function location(array $node): array
    {
        $lat = (float) ($node['degreesLatitude'] ?? 0);
        $lng = (float) ($node['degreesLongitude'] ?? 0);
        $name = $this->clean((string) ($node['name'] ?? ''), 200);
        $address = $this->clean((string) ($node['address'] ?? ''), 300);

        $descripcion = $address !== '' ? $address : ($name !== '' ? $name : sprintf('%.6F, %.6F', $lat, $lng));

        return [
            'body' => $this->truncate('[ubicación] '.$descripcion, self::BODY_MAX_BYTES),
            'media_type' => 'location',
            'media_mime' => null,
            'media_base64' => null,
            'media_payload' => array_filter([
                'lat' => $lat,
                'lng' => $lng,
                'name' => $name !== '' ? $name : null,
                'address' => $address !== '' ? $address : null,
            ], static fn ($v) => $v !== null),
        ];
    }

    /**
     * El vCard se parsea a campos concretos; NO se guarda crudo ni se renderiza
     * (§6.7): es texto arbitrario de un tercero.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array{body: string, media_type: string, media_mime: null, media_base64: null, media_payload: array<string, mixed>}
     */
    private function contacts(array $nodes): array
    {
        $contacts = [];

        foreach ($nodes as $node) {
            $vcard = (string) ($node['vcard'] ?? '');
            $name = $this->clean((string) ($node['displayName'] ?? ''), self::NAME_MAX_BYTES);

            if ($name === '') {
                $name = $this->clean($this->vcardField($vcard, 'FN'), self::NAME_MAX_BYTES);
            }

            $contacts[] = [
                'name' => $name !== '' ? $name : 'Contacto',
                'phones' => $this->vcardPhones($vcard),
            ];
        }

        $names = implode(', ', array_column($contacts, 'name'));

        return [
            'body' => $this->truncate('[contacto] '.$names, self::BODY_MAX_BYTES),
            'media_type' => 'contact',
            'media_mime' => null,
            'media_base64' => null,
            'media_payload' => ['contacts' => $contacts],
        ];
    }

    /** Primer valor de una propiedad vCard (`FN:Juan Pérez`). */
    private function vcardField(string $vcard, string $field): string
    {
        if (preg_match('/^'.preg_quote($field, '/').'[^:\r\n]*:(.*)$/mi', $vcard, $m) === 1) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * Teléfonos del vCard. WhatsApp escribe `TEL;type=CELL;waid=57300…:+57 300 …`.
     *
     * @return list<string>
     */
    private function vcardPhones(string $vcard): array
    {
        // preg_match_all devuelve la CANTIDAD de coincidencias, no un booleano.
        if (! preg_match_all('/^TEL[^:\r\n]*:(.+)$/mi', $vcard, $m)) {
            return [];
        }

        $phones = [];
        foreach ($m[1] as $raw) {
            // Se conservan solo dígitos y el `+` inicial: el resto es formato.
            $clean = preg_replace('/(?!^\+)[^0-9]/', '', trim($raw)) ?? '';
            if ($clean !== '' && $clean !== '+') {
                $phones[] = mb_strcut($clean, 0, 24);
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * `fileLength` NO es un entero: es un Long de protobuf `{low, high, unsigned}`
     * (verificado en F0). Un cast directo da basura.
     */
    private function fileLength(mixed $value): ?int
    {
        if (is_array($value)) {
            return isset($value['low']) ? (int) $value['low'] : null;
        }

        // Algunos nodos lo mandan como string decimal.
        return is_numeric($value) ? (int) $value : null;
    }

    /** Epoch en segundos → zona de la app, igual que el camino de Meta. */
    private function timestamp(mixed $value): Carbon
    {
        if (is_array($value)) {
            $value = $value['low'] ?? null;
        }

        return is_numeric($value)
            ? Carbon::createFromTimestamp((int) $value, config('app.timezone'))
            : now();
    }

    private function clean(string $value, int $maxBytes, bool $allowWhitespace = false): string
    {
        return $this->truncate(SafePlainText::sanitize($value, $allowWhitespace), $maxBytes);
    }

    /** Corte por BYTES sin partir un carácter multibyte a la mitad. */
    private function truncate(string $value, int $maxBytes): string
    {
        return strlen($value) > $maxBytes ? mb_strcut($value, 0, $maxBytes) : $value;
    }
}

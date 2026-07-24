<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Models\CompanyWhatsappAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP fino contra Evolution API v2.3.7. Un método por endpoint, nada de
 * lógica de negocio (plan 8-whatsapp.md §6.1-6.2).
 *
 * Un solo proveedor ⇒ clase concreta. Nada de interfaz con una implementación.
 *
 * Dos tokens distintos, no intercambiables:
 *  - el GLOBAL (`config('evolution.global_token')`) gestiona instancias;
 *  - el de INSTANCIA (`evo_token_encrypted` del canal) manda mensajes.
 *
 * Todas las firmas de abajo están verificadas contra 2.3.7 provocando los
 * errores de validación del servidor, no tomadas de la documentación.
 */
class EvolutionClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $globalToken,
    ) {}

    public static function default(): self
    {
        return new self(
            rtrim(self::resolveBaseUrl((string) config('evolution.base_url')), '/'),
            (string) config('evolution.global_token'),
        );
    }

    /** Cliente apuntado al servidor del canal, que puede no ser el default. */
    public static function forAccount(CompanyWhatsappAccount $account): self
    {
        $configured = (string) ($account->evo_server_url ?: config('evolution.base_url'));

        return new self(
            rtrim(self::resolveBaseUrl($configured), '/'),
            (string) config('evolution.global_token'),
        );
    }

    /**
     * Resuelve la URL EFECTIVA de Evolution para el host actual (N-instance).
     *
     * Evolution solo puede tener el socket Baileys en UNA instancia (el líder
     * del leader-guard); corre atado a su ENI privada. Con N≥2 instancias, la
     * que NO es líder no tiene Evolution en su loopback, así que apuntar a
     * `127.0.0.1:8080` fallaría. Aquí, si la URL configurada es loopback, se
     * reemplaza el host por la IP privada del líder (leída de la tabla-heartbeat
     * `evolution_leader` que mantiene el guard). Una `evo_server_url` externa
     * real (multi-servidor) NUNCA se toca.
     *
     * Sin líder fresco (o la tabla aún no existe): se deja el loopback — en ese
     * momento no hay Evolution vivo en ningún lado, así que da igual, y el caller
     * ya trata el fallo de transporte como resultado, no excepción.
     */
    private static function resolveBaseUrl(string $configured): string
    {
        $host = parse_url($configured, PHP_URL_HOST);

        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return $configured;
        }

        $leaderIp = self::leaderIp();
        if ($leaderIp === null) {
            return $configured;
        }

        $port = parse_url($configured, PHP_URL_PORT);

        return 'http://'.$leaderIp.($port ? ':'.$port : '');
    }

    /**
     * IP privada del líder de Evolution, o null si no hay latido fresco. Cache
     * corto: el líder es GLOBAL (no per-instancia), así que el store compartido
     * (`database`) es correcto — todas las instancias deben apuntar al mismo.
     */
    private static function leaderIp(): ?string
    {
        return Cache::remember('evolution:leader_ip', now()->addSeconds(10), static function (): ?string {
            try {
                $stale = (int) config('evolution.leader_stale_seconds', 90);
                $row = DB::selectOne(
                    'SELECT holder_ip FROM public.evolution_leader
                      WHERE id = 1 AND holder_ip IS NOT NULL
                        AND heartbeat_at > now() - make_interval(secs => ?)',
                    [$stale],
                );

                return $row->holder_ip ?? null;
            } catch (\Throwable) {
                // Tabla ausente (Evolution nunca arrancó) o BD inalcanzable:
                // se cae al loopback configurado.
                return null;
            }
        });
    }

    // ── Ciclo de vida de la instancia (token global) ─────────────────────────

    /**
     * `number` es opcional: enviándolo, Evolution devuelve además un
     * `pairingCode` de 8 dígitos para vincular sin cámara.
     *
     * @return array<string, mixed>
     */
    public function createInstance(string $instance, ?string $phoneE164 = null): array
    {
        $body = [
            'instanceName' => $instance,
            'integration' => 'WHATSAPP-BAILEYS',
            'qrcode' => true,
        ];

        if ($phoneE164 !== null) {
            $body['number'] = self::toMsisdn($phoneE164);
        }

        return $this->request('post', 'instance/create', $this->globalToken, $body);
    }

    /** @return array<string, mixed> QR en `base64` (data URI) y/o `pairingCode`. */
    public function connect(string $instance): array
    {
        return $this->request('get', "instance/connect/{$instance}", $this->globalToken);
    }

    /** @return array<string, mixed> `instance.state` ∈ open|connecting|close. */
    public function connectionState(string $instance): array
    {
        return $this->request('get', "instance/connectionState/{$instance}", $this->globalToken);
    }

    /** @return array<string, mixed> */
    public function logout(string $instance): array
    {
        return $this->request('delete', "instance/logout/{$instance}", $this->globalToken);
    }

    /** @return array<string, mixed> */
    public function deleteInstance(string $instance): array
    {
        return $this->request('delete', "instance/delete/{$instance}", $this->globalToken);
    }

    /**
     * Registra el webhook del canal.
     *
     * 🔴 El body va ANIDADO bajo `webhook` (verificado en F0). Plano da 400.
     *
     * Los eventos se suscriben en MAYÚSCULAS pero llegan en dot.case minúscula
     * (`messages.upsert`): son dos vocabularios distintos, ver EvolutionWebhookController.
     *
     * @param  array<string, string>  $headers
     * @param  list<string>  $events
     * @return array<string, mixed>
     */
    public function setWebhook(string $instance, string $url, array $headers, array $events): array
    {
        return $this->request('post', "webhook/set/{$instance}", $this->globalToken, [
            'webhook' => [
                'enabled' => true,
                'url' => $url,
                'byEvents' => false,
                // Trae el binario embebido en el evento. Es la ÚNICA vía: el
                // fallback por getBase64FromMediaMessage no existe con la
                // config de §4.6 (la tabla "Message" está vacía).
                'base64' => true,
                'headers' => $headers,
                'events' => $events,
            ],
        ]);
    }

    // ── Mensajería (token de instancia) ──────────────────────────────────────

    /** @return array<string, mixed> */
    public function sendText(string $instance, string $token, string $phoneE164, string $text): array
    {
        return $this->request('post', "message/sendText/{$instance}", $token, [
            'number' => self::toMsisdn($phoneE164),
            'text' => $text,
        ]);
    }

    /**
     * `mediatype` es un enum CERRADO: image|document|video|audio. No incluye
     * sticker — para eso está sendSticker.
     *
     * `media` acepta URL o base64. Se manda la URL prefirmada de S3 para no
     * empujar 16 MB en base64 por PHP-FPM (§6.7).
     *
     * @return array<string, mixed>
     */
    public function sendMedia(
        string $instance,
        string $token,
        string $phoneE164,
        string $mediaType,
        string $media,
        ?string $fileName = null,
        ?string $caption = null,
    ): array {
        $body = [
            'number' => self::toMsisdn($phoneE164),
            'mediatype' => $mediaType,
            'media' => $media,
        ];

        if ($fileName !== null) {
            $body['fileName'] = $fileName;
        }
        if ($caption !== null && $caption !== '') {
            $body['caption'] = $caption;
        }

        return $this->request('post', "message/sendMedia/{$instance}", $token, $body);
    }

    /** Nota de voz (PTT). Distinto de sendMedia con mediatype=audio, que manda un archivo. */
    /** @return array<string, mixed> */
    public function sendWhatsAppAudio(string $instance, string $token, string $phoneE164, string $audio): array
    {
        return $this->request('post', "message/sendWhatsAppAudio/{$instance}", $token, [
            'number' => self::toMsisdn($phoneE164),
            'audio' => $audio,
        ]);
    }

    /** @return array<string, mixed> */
    public function sendSticker(string $instance, string $token, string $phoneE164, string $sticker): array
    {
        return $this->request('post', "message/sendSticker/{$instance}", $token, [
            'number' => self::toMsisdn($phoneE164),
            'sticker' => $sticker,
        ]);
    }

    /**
     * 🔴 `name` y `address` son REQUERIDOS acá, pero la ubicación entrante llega
     * SIN ninguno de los dos (verificado en F0). Reenviar una ubicación recibida
     * tal cual da 400: por eso el caller sintetiza ambos desde las coordenadas.
     *
     * @return array<string, mixed>
     */
    public function sendLocation(
        string $instance,
        string $token,
        string $phoneE164,
        float $latitude,
        float $longitude,
        string $name,
        string $address,
    ): array {
        return $this->request('post', "message/sendLocation/{$instance}", $token, [
            'number' => self::toMsisdn($phoneE164),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'name' => $name,
            'address' => $address,
        ]);
    }

    /**
     * Cada contacto exige `fullName` y `phoneNumber` (camelCase).
     *
     * @param  list<array{fullName: string, phoneNumber: string, wuid?: string, organization?: string}>  $contacts
     * @return array<string, mixed>
     */
    public function sendContact(string $instance, string $token, string $phoneE164, array $contacts): array
    {
        return $this->request('post', "message/sendContact/{$instance}", $token, [
            'number' => self::toMsisdn($phoneE164),
            'contact' => $contacts,
        ]);
    }

    /**
     * Indicador de presencia (§9.3: "escribiendo…"). Con Baileys se puede; con
     * Cloud API no. `presence` ∈ available|unavailable|composing|recording|paused.
     * `delay` en ms es cuánto lo sostiene Evolution. Best-effort desde el bot.
     *
     * @return array<string, mixed>
     */
    public function sendPresence(
        string $instance,
        string $token,
        string $phoneE164,
        string $presence = 'composing',
        int $delayMs = 3000,
    ): array {
        return $this->request('post', "chat/sendPresence/{$instance}", $token, [
            'number' => self::toMsisdn($phoneE164),
            'presence' => $presence,
            'delay' => $delayMs,
        ]);
    }

    /**
     * Marca leído. Exige la clave COMPLETA del mensaje —  Meta se conformaba con
     * el wamid suelto. Verificado en F0.
     *
     * @return array<string, mixed>
     */
    public function markRead(string $instance, string $token, string $messageId, string $remoteJid, bool $fromMe = false): array
    {
        return $this->request('post', "chat/markMessageAsRead/{$instance}", $token, [
            'readMessages' => [[
                'id' => $messageId,
                'fromMe' => $fromMe,
                'remoteJid' => $remoteJid,
            ]],
        ]);
    }

    /**
     * ⚠️ NO se cablea al camino de entrada. Lee la tabla "Message" de Evolution,
     * que está vacía porque §4.6 fija `DATABASE_SAVE_DATA_NEW_MESSAGE=false`:
     * con un mensaje real devuelve `400 {"message":["Message not found"]}`
     * (verificado en F0). Queda como escotilla de diagnóstico por si algún día
     * se activa esa variable.
     *
     * @return array<string, mixed>
     */
    public function fetchMediaBase64(string $instance, string $token, string $messageId, string $remoteJid, bool $fromMe = false): array
    {
        return $this->request('post', "chat/getBase64FromMediaMessage/{$instance}", $token, [
            'message' => ['key' => [
                'remoteJid' => $remoteJid,
                'fromMe' => $fromMe,
                'id' => $messageId,
            ]],
        ]);
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    /** Evolution habla msisdn sin `+`; bistro guarda E.164 con `+`. */
    public static function toMsisdn(string $phoneE164): string
    {
        return ltrim($phoneE164, '+');
    }

    /** `573001112233` → `573001112233@s.whatsapp.net` */
    public static function toJid(string $phoneE164): string
    {
        return self::toMsisdn($phoneE164).'@s.whatsapp.net';
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> Siempre incluye `ok` (bool) y `status` (int).
     */
    private function request(string $method, string $path, string $token, array $body = []): array
    {
        try {
            $client = $this->http($token);
            $response = $method === 'get' || $method === 'delete'
                ? $client->{$method}("{$this->baseUrl}/{$path}")
                : $client->{$method}("{$this->baseUrl}/{$path}", $body);
        } catch (\Throwable $e) {
            // Evolution caído o inalcanzable: no es excepción del caller, es un
            // resultado que el servicio de canal sabe interpretar.
            Log::channel('single')->warning('evolution.request.transport_error', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return ['ok' => false, 'status' => 0, 'error' => 'transport_error'];
        }

        $json = $response->json() ?? [];

        if (! $response->successful()) {
            Log::channel('single')->warning('evolution.request.failed', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $json,
            ]);
        }

        return ['ok' => $response->successful(), 'status' => $response->status()] + $json;
    }

    private function http(string $token): PendingRequest
    {
        return Http::withHeaders(['apikey' => $token])
            ->timeout((int) config('evolution.timeout', 15))
            ->acceptJson();
    }
}

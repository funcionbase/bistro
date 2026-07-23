<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyWhatsappAccount;
use App\Models\CompanyWhatsappAccountEvent;
use App\Models\WebhookEvent;
use App\Services\Whatsapp\AutomationDispatcher;
use App\Services\Whatsapp\EvolutionChannelService;
use App\Services\Whatsapp\EvolutionInboundMapper;
use App\Services\Whatsapp\WhatsappInboundMessageHandler;
use App\Support\RedactsPii;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Webhook público de Evolution API (plan 8-whatsapp.md §6.3).
 *
 *   POST /api/v1/webhooks/whatsapp/evolution/{account}
 *
 * Sin JWT: la autenticidad la da un secreto de 32 bytes por canal, entregado a
 * Evolution en los `headers` del `/webhook/set` y guardado cifrado en
 * `inbound_secret_encrypted`.
 *
 * ⚠️ Los nombres de evento se SUSCRIBEN en MAYÚSCULAS (`MESSAGES_UPSERT`) pero
 * LLEGAN en dot.case minúscula (`messages.upsert`). Son dos vocabularios
 * distintos; el match de abajo va contra los de minúscula.
 */
class EvolutionWebhookController extends Controller
{
    /**
     * Valor contra el que se compara cuando el canal no existe. Tiene el largo
     * de un secreto real para que la comparación cueste lo mismo.
     */
    private const DUMMY_SECRET = '00000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private readonly WhatsappInboundMessageHandler $inboundHandler,
        private readonly EvolutionInboundMapper $mapper,
    ) {}

    public function receive(Request $request, string $account): JsonResponse
    {
        // 1. AUTENTICACIÓN — antes de tocar una sola tabla de negocio.
        //
        // El canal se busca SIEMPRE y la comparación se hace SIEMPRE, exista o
        // no. Devolver 404 rápido para un `{account}` inexistente y 401 lento
        // para un secreto malo convierte el tiempo de respuesta en un oráculo:
        // permitiría enumerar qué canales existen, que es justo lo que el 401
        // idéntico busca impedir (§7.7 caso 8).
        // El id se valida ANTES de la consulta: la columna es `uuid` y un valor
        // que no lo sea hace fallar a PostgreSQL con 22P02. Sin esta guarda, un
        // `{account}` malformado devuelve 500 con el error de la base y uno bien
        // formado devuelve 401 — otra vez un oráculo, y encima con fuga de
        // detalles del schema.
        $channel = Str::isUuid($account)
            ? CompanyWhatsappAccount::query()->find($account)
            : null;

        $expected = $channel?->inboundSecret() ?: self::DUMMY_SECRET;
        $provided = (string) $request->header((string) config('evolution.webhook.header'), '');

        $signatureValid = hash_equals($expected, $provided) && $channel !== null;

        if (! $signatureValid) {
            $this->logEvent($account, $request->json()->all(), false);

            Log::channel('single')->warning('evolution.webhook.rejected', [
                'account' => $account,
                'channel_exists' => $channel !== null,
                'has_header' => $provided !== '',
            ]);

            // Respuesta IDÉNTICA en los dos casos: mismo status, mismo cuerpo.
            return response()->json(['status' => 'unauthorized'], 401);
        }

        // 2. ORIGEN — rate limit por canal + allowlist de IP (si está configurada).
        if (! $this->originAllowed($request, $account)) {
            return response()->json(['status' => 'too_many_requests'], 429);
        }

        $payload = $request->json()->all();

        // 3. PERSISTENCIA ACOTADA — sin binarios y sin credenciales.
        $event = $this->logEvent($account, $payload, true);

        // 3b. REPLAY — reserva atómica por (canal, id de mensaje) ANTES de despachar.
        //     Evolution puede reentregar un `messages.upsert`; sin este guard un
        //     evento reentregado se procesa dos veces. `persistInbound` ya dedupea
        //     el CONTENIDO por `meta_message_id`, pero el resto del dispatch (el
        //     away-reply, el push) no — este guard corta antes de todo eso.
        //     `Cache::add` es atómico; con `CACHE_STORE=database` el lock vive en
        //     Postgres → cross-instance en el ASG (§4.7).
        //     Solo `upsert`: los acks (`messages.update`) ya son idempotentes por
        //     su lógica monotónica y comparten `keyId` entre estados distintos del
        //     mismo mensaje, así que dedupearlos por id los rompería.
        // ponytail: TTL 6 h — holgado para la reentrega de Evolution (reintenta
        //     poco) sin acumular claves; el id de mensaje es único, no colisiona.
        $replayKey = ($payload['event'] ?? null) === 'messages.upsert'
            ? ($payload['data']['key']['id'] ?? null)
            : null;

        if ($replayKey !== null && ! Cache::add("evo:{$account}:{$replayKey}", true, now()->addHours(6))) {
            $event?->forceFill(['processed_at' => now(), 'attempts' => ($event->attempts ?? 0) + 1])->save();

            return response()->json(['status' => 'duplicate'], 200);
        }

        // 4. DESPACHO.
        try {
            $stats = $this->dispatch($channel, $payload);
        } catch (\Throwable $e) {
            // El dispatch falló: soltamos la reserva para que el reintento LEGÍTIMO
            // de Evolution vuelva a procesar. El guard es contra el doble
            // procesamiento EXITOSO, no contra reintentar un fallo.
            if ($replayKey !== null) {
                Cache::forget("evo:{$account}:{$replayKey}");
            }

            $event?->forceFill([
                // Redactado: el mensaje de una excepcion de Postgres incluye
                // `DETAIL: Failing row contains (...)` con el telefono y el
                // cuerpo del mensaje. Ver App\Support\RedactsPii.
                'error' => RedactsPii::exceptionMessage($e->getMessage(), 65000),
                'attempts' => ($event->attempts ?? 0) + 1,
            ])->save();

            Log::channel('single')->error('evolution.webhook.handler_error', [
                'account' => $account,
                'message' => RedactsPii::exceptionMessage($e->getMessage()),
            ]);

            return response()->json(['status' => 'error'], 500);
        }

        $event?->forceFill([
            'processed_at' => now(),
            'attempts' => ($event->attempts ?? 0) + 1,
            'error' => null,
        ])->save();

        return response()->json(['status' => 'ok'] + $stats);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int|string>
     */
    private function dispatch(CompanyWhatsappAccount $channel, array $payload): array
    {
        $event = (string) ($payload['event'] ?? '');
        $data = (array) ($payload['data'] ?? []);

        return match ($event) {
            'qrcode.updated' => $this->onQrUpdated($channel, $data),
            'connection.update' => $this->onConnectionUpdate($channel, $data),
            'messages.upsert' => $this->onMessageUpsert($channel, $data),
            'messages.update' => $this->onMessageUpdate($channel, $data),
            default => ['ignored' => $event],
        };
    }

    /**
     * El QR es un secreto de sesión efímero: va a caché con TTL corto y NUNCA a
     * una tabla. Lo consume el polling del wizard (§8.3).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int|string>
     */
    private function onQrUpdated(CompanyWhatsappAccount $channel, array $data): array
    {
        $qr = $data['qrcode']['base64'] ?? null;

        if (is_string($qr) && $qr !== '') {
            EvolutionChannelService::make()->cacheQr($channel, $qr);
        }

        return ['qr_cached' => is_string($qr) ? 1 : 0];
    }

    /**
     * `close` NO es un solo caso: hay que leer `statusReason`. Un 401 es la
     * credencial muerta — ninguna reconexión automática lo arregla y el cliente
     * tiene que re-escanear. Otros códigos suelen ser transitorios y Baileys
     * reconecta solo. Tratarlos igual da alertas falsas en cada corte de red, o
     * canales muertos en silencio.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int|string>
     */
    private function onConnectionUpdate(CompanyWhatsappAccount $channel, array $data): array
    {
        $state = (string) ($data['state'] ?? '');
        $statusReason = isset($data['statusReason']) ? (int) $data['statusReason'] : null;

        $attributes = ['last_connection_check_at' => now()];

        if ($state === 'open') {
            $attributes['status'] = 'connected';
            $attributes['connected_at'] = $channel->connected_at ?? now();
            $attributes['last_error'] = null;

            // El payload de `open` trae el número y el nombre del perfil: el
            // wizard no necesita pedírselos al usuario ni arriesgar un tipeo.
            if (! empty($data['wuid']) && empty($channel->phone_e164)) {
                $wuid = (string) $data['wuid'];
                $msisdn = strstr($wuid, '@', true) ?: $wuid;
                $attributes['phone_e164'] = '+'.$msisdn;
            }
            if (! empty($data['profileName']) && empty($channel->display_name)) {
                $attributes['display_name'] = mb_substr((string) $data['profileName'], 0, 120);
            }
        } elseif ($state === 'connecting') {
            $attributes['status'] = 'verifying';
        } else {
            $attributes['status'] = 'disconnected';
            $attributes['disconnected_at'] = now();
            $attributes['last_error'] = $statusReason === 401
                ? 'session_invalidated'   // credencial muerta: re-escanear
                : 'connection_closed';    // transitorio: Baileys reintenta
        }

        $channel->forceFill($attributes)->save();

        // F6 (§9.2): avisar a n8n del cambio de estado del canal, si hay flujo
        // suscrito. El dispatcher resuelve el flujo por (empresa, sede) del canal.
        app(AutomationDispatcher::class)->forChannel(
            AutomationDispatcher::EVENT_CHANNEL_STATUS,
            $channel,
            ['status' => $attributes['status'] ?? $channel->status, 'state' => $state],
        );

        CompanyWhatsappAccountEvent::create([
            'company_whatsapp_account_id' => $channel->id,
            'event_type' => 'connection_'.($state !== '' ? $state : 'unknown'),
            // `statusReason` decide el copy de la UI: "Reconectando…" vs
            // "Volvé a escanear el QR".
            'payload' => ['state' => $state, 'status_reason' => $statusReason],
            'created_at' => now(),
        ]);

        return ['state' => $state];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int|string>
     */
    private function onMessageUpsert(CompanyWhatsappAccount $channel, array $data): array
    {
        $normalized = $this->mapper->map($data);

        if ($normalized === null) {
            return ['messages_skipped' => 1];
        }

        $message = $this->inboundHandler->persistInbound($channel, $normalized);

        return $message !== null
            ? ['messages_processed' => 1]
            : ['messages_duplicated' => 1];
    }

    /**
     * Acks. Llegan SIEMPRE con `remoteJid` en `@lid` — 11 de 11 en la muestra de
     * F0 — así que la regla de ignorar `@lid` NO aplica acá: se resuelven por
     * `keyId` contra `chat_messages.meta_message_id` y el jid no se usa. Aplicar
     * la regla acá dejaría el 100 % de los mensajes sin marcar.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int|string>
     */
    private function onMessageUpdate(CompanyWhatsappAccount $channel, array $data): array
    {
        // Los acks llegan con `fromMe` en los dos valores. Los de `false` son el
        // eco de NUESTRO propio read receipt sobre un mensaje entrante: aplicarlos
        // pondría `status='read'` en una fila del cliente, y la UI pinta los
        // chulitos desde esa columna — saldrían sobre la burbuja del cliente. Los
        // ticks solo describen mensajes que mandamos nosotros.
        if (! ($data['fromMe'] ?? $data['key']['fromMe'] ?? false)) {
            return ['statuses_skipped' => 1];
        }

        $keyId = $data['keyId'] ?? ($data['key']['id'] ?? null);

        // Valores de Baileys en UPPER_SNAKE, no los de Meta.
        $status = match ((string) ($data['status'] ?? '')) {
            'SERVER_ACK', 'PENDING' => 'sent',
            'DELIVERY_ACK' => 'delivered',
            'READ', 'PLAYED' => 'read',
            'ERROR' => 'failed',
            default => null,
        };

        if ($keyId === null || $status === null) {
            return ['statuses_skipped' => 1];
        }

        // Reutiliza la lógica monotónica del handler, con su guarda de `failed`
        // como terminal: no se duplica ni se reimplementa.
        $applied = $this->inboundHandler->applyOutboundStatus($channel, [
            'id' => (string) $keyId,
            'status' => $status,
        ]);

        return ['statuses_processed' => $applied ? 1 : 0];
    }

    private function originAllowed(Request $request, string $account): bool
    {
        $allowed = (array) config('evolution.webhook.allowed_ips', []);

        // Lista vacía = allowlist desactivada (desarrollo local, §6.3).
        if ($allowed !== [] && ! in_array((string) $request->ip(), $allowed, true)) {
            Log::channel('single')->warning('evolution.webhook.ip_rejected', [
                'account' => $account,
                'ip' => $request->ip(),
            ]);

            return false;
        }

        $limit = (int) config('evolution.webhook.rate_limit', 240);

        if (RateLimiter::tooManyAttempts("evolution-webhook:{$account}", $limit)) {
            return false;
        }

        RateLimiter::hit("evolution-webhook:{$account}", 60);

        return true;
    }

    /**
     * Fila en `webhook_events` para idempotencia y replay, con lo que NO puede
     * guardarse ya removido.
     *
     * @param  array<string, mixed>  $payload
     */
    private function logEvent(string $account, array $payload, bool $signatureValid): ?WebhookEvent
    {
        try {
            $event = (string) ($payload['event'] ?? '');

            return WebhookEvent::create([
                'provider' => 'evolution',
                // `event_id` solo existe para eventos de mensaje. `connection.update`
                // y `qrcode.updated` no lo tienen: van en null y sin dedupe — son
                // idempotentes por naturaleza (aplican un estado, no acumulan).
                'event_id' => str_starts_with($event, 'messages.')
                    ? ($payload['data']['key']['id'] ?? $payload['data']['keyId'] ?? null)
                    : null,
                'payload' => $this->scrub($payload),
                // El header de autenticación NO se guarda: meter el secreto en
                // claro en la BD es peor que no tener el dato. Solo el veredicto.
                'signature_header' => null,
                'signature_valid' => $signatureValid,
                'received_at' => now(),
                'attempts' => 0,
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->error('evolution.webhook.persist_failed', [
                'account' => $account,
                'message' => RedactsPii::exceptionMessage($e->getMessage()),
            ]);

            // A diferencia de Meta, acá NO se devuelve 500: el evento ya está en
            // memoria y procesarlo vale más que registrarlo. Evolution reintenta
            // poco y perder un mensaje es peor que perder su bitácora.
            return null;
        }
    }

    /**
     * Quita del payload lo que no puede vivir en la BD:
     *
     *  1. `base64` — un video de 16 MB entra entero en la columna `payload`
     *     (jsonb), multiplicado por cada reintento. El binario ya va a S3.
     *  2. `apikey` — cada webhook trae el token de la instancia EN CLARO
     *     (hallazgo F0). Sin esto, cada fila queda con una credencial
     *     reutilizable dentro de la BD compartida, y `webhook_events` no tiene
     *     política de purga.
     *  3. `qrcode` — es un secreto de sesión; su lugar es la caché con TTL.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function scrub(array $payload): array
    {
        array_walk_recursive($payload, static function (&$value, $key): void {
            if ($key === 'base64' && is_string($value)) {
                $value = '[omitido: '.strlen($value).' chars]';
            } elseif ($key === 'apikey') {
                $value = '[redactado]';
            } elseif (in_array($key, ['code', 'pairingCode'], true) && is_string($value)) {
                $value = '[redactado]';
            }
        });

        return $payload;
    }
}

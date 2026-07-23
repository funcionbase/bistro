<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaPlatformCredential;
use App\Models\WebhookEvent;
use App\Services\Whatsapp\WhatsappInboundMessageHandler;
use App\Services\Whatsapp\WhatsappSignatureValidator;
use App\Support\RedactsPii;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Webhook publico de WhatsApp Cloud API.
 *
 * Endpoints:
 *   GET  /api/v1/webhooks/whatsapp  → handshake con Meta (verify token).
 *   POST /api/v1/webhooks/whatsapp  → recibe eventos (mensajes entrantes,
 *                                     status updates, account updates).
 *
 * Sin JWT ni middleware de empresa: la autenticidad la garantiza la firma
 * HMAC SHA-256 del header X-Hub-Signature-256 calculada con el App Secret.
 *
 * NOTA: mientras n8n esta en desarrollo, los mensajes se persisten directo en
 * `chats` con `bot_paused=true` para que el operador atienda manualmente. No
 * hay forwarding a n8n todavia. Ver WhatsappInboundMessageHandler.
 */
class WhatsappWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsappSignatureValidator $signatureValidator,
        private readonly WhatsappInboundMessageHandler $inboundHandler,
    ) {}

    /**
     * Handshake de verificacion. Meta llama una sola vez al configurar el
     * webhook y espera devolvamos exactamente hub.challenge en el body.
     */
    public function verify(Request $request): SymfonyResponse
    {
        $mode = $request->query('hub_mode');
        $providedToken = (string) $request->query('hub_verify_token', '');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe' || $challenge === null) {
            return new Response('Bad Request', 400);
        }

        $credential = MetaPlatformCredential::activeForCurrentEnvironment();

        if ($credential === null) {
            Log::channel('single')->error('whatsapp.webhook.verify.no_credentials');

            return new Response('Forbidden', 403);
        }

        $expected = $credential->webhookVerifyToken();

        if (empty($expected) || ! hash_equals($expected, $providedToken)) {
            Log::channel('single')->warning('whatsapp.webhook.verify.token_mismatch');

            return new Response('Forbidden', 403);
        }

        return new Response((string) $challenge, 200);
    }

    /**
     * Recibe el payload de eventos de WhatsApp. Responde 200 lo antes posible
     * (Meta reintenta agresivamente si el endpoint tarda > 20s o devuelve >= 500).
     *
     * Durabilidad: persistimos el payload crudo en webhook_events APENAS LLEGA,
     * antes de validar firma o procesar. Si el handler falla (BD caida, bug,
     * etc.), el evento queda para replay manual via `whatsapp:replay-events`.
     * Si la persistencia inicial falla, devolvemos 500 para que Meta reintente.
     */
    public function receive(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signatureHeader = $request->header('X-Hub-Signature-256');
        $payload = $request->json()->all();

        $event = $this->logEvent($payload, $signatureHeader);
        if ($event === null) {
            // Si ni siquiera podemos persistir, forzamos retry de Meta.
            return response()->json(['status' => 'error', 'reason' => 'persist_failed'], 500);
        }

        $credential = MetaPlatformCredential::activeForCurrentEnvironment();
        $signatureValid = $this->signatureValidator->isValid($rawPayload, $signatureHeader, $credential);

        $event->forceFill(['signature_valid' => $signatureValid])->save();

        if (! $signatureValid) {
            Log::channel('single')->warning('whatsapp.webhook.invalid_signature', [
                'event_id' => $event->id,
                'has_header' => $signatureHeader !== null,
            ]);

            // 200 para evitar backoff pero el evento queda persistido para replay
            // si la firma fue invalida por rotacion de secrets.
            return response()->json(['status' => 'ignored', 'reason' => 'invalid_signature']);
        }

        try {
            $stats = $this->inboundHandler->handle($payload);
        } catch (\Throwable $e) {
            $event->forceFill([
                'error' => RedactsPii::exceptionMessage($e->getMessage(), 65000),
                'attempts' => $event->attempts + 1,
            ])->save();

            Log::channel('single')->error('whatsapp.webhook.handler_error', [
                'event_id' => $event->id,
                'message' => RedactsPii::exceptionMessage($e->getMessage()),
            ]);

            // 500 fuerza retry de Meta. El log + payload quedan en webhook_events.
            return response()->json(['status' => 'error', 'event_id' => $event->id], 500);
        }

        $event->forceFill([
            'processed_at' => now(),
            'attempts' => $event->attempts + 1,
            'error' => null,
        ])->save();

        return response()->json(['status' => 'ok', 'event_id' => $event->id] + $stats);
    }

    /**
     * Persiste el payload crudo en webhook_events. Usa try/catch porque si esto
     * falla queremos forzar un retry de Meta (devolver 500), no un crash.
     */
    private function logEvent(array $payload, ?string $signatureHeader): ?WebhookEvent
    {
        try {
            return WebhookEvent::create([
                'provider' => 'meta_whatsapp',
                'event_id' => $payload['entry'][0]['id'] ?? null,
                'payload' => $payload,
                'signature_header' => $signatureHeader,
                'signature_valid' => false,
                'received_at' => now(),
                'attempts' => 0,
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->error('whatsapp.webhook.persist_failed', [
                'message' => RedactsPii::exceptionMessage($e->getMessage()),
            ]);

            return null;
        }
    }
}

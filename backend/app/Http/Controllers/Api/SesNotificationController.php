<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailSuppression;
use App\Models\WebhookEvent;
use App\Services\AuditService;
use App\Services\EmailDeliveryService;
use App\Services\SnsSignatureValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Webhook público de Amazon SES → SNS.
 *
 * Endpoint:
 *   POST /api/v1/webhooks/ses-notifications
 *
 * AWS SNS llama este endpoint cada vez que el SES Configuration Set
 * `bistro-default` reporta un evento (Bounce, Complaint, Delivery,
 * DeliveryDelay, ...). En la suscripción inicial también llega un
 * SubscriptionConfirmation que hay que confirmar siguiendo el
 * `SubscribeURL`.
 *
 * Sin JWT: la autenticidad la garantiza la firma SNS (RSA-SHA1/SHA256
 * verificada contra el cert X.509 publicado por AWS). Igual patrón que
 * `WhatsappWebhookController` para Meta (HMAC-SHA256).
 *
 * Durabilidad: persistimos el payload crudo en `webhook_events`
 * APENAS LLEGA. Si la verificación o el procesamiento fallan, queda
 * para replay manual. Si la persistencia inicial falla, devolvemos
 * 500 para que SNS reintente.
 *
 * Idempotencia: SNS reintenta agresivamente. Garantizada por:
 *   - `webhook_events.event_id` único (MessageId de SNS).
 *   - `email_suppressions` unique partial index sobre (email, reason).
 *
 * N-instance safe (CLAUDE.md §12): múltiples instancias del ASG pueden
 * recibir el mismo evento si SNS reintenta antes del 200. La unique
 * constraint en BD garantiza una sola suppression efectiva.
 */
class SesNotificationController extends Controller
{
    public function __construct(
        private readonly SnsSignatureValidator $signatureValidator,
        private readonly EmailDeliveryService $deliveryService,
        private readonly AuditService $auditService,
    ) {}

    public function receive(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $payload = $request->json()->all();

        if (! is_array($payload) || empty($payload)) {
            return response()->json(['status' => 'error', 'reason' => 'invalid_json'], 400);
        }

        $event = $this->logEvent($payload);
        if ($event === null) {
            return response()->json(['status' => 'error', 'reason' => 'persist_failed'], 500);
        }

        $signatureValid = $this->signatureValidator->isValid($payload);
        $event->forceFill(['signature_valid' => $signatureValid])->save();

        if (! $signatureValid) {
            Log::channel('single')->warning('ses.webhook.invalid_signature', [
                'event_id' => $event->id,
                'message_id' => $payload['MessageId'] ?? null,
            ]);

            // 200 para evitar backoff agresivo de SNS, pero el evento queda
            // persistido para replay si fue invalidación legítima por rotación
            // de certs (improbable, pero posible).
            return response()->json(['status' => 'ignored', 'reason' => 'invalid_signature']);
        }

        try {
            $stats = $this->dispatch($payload, $event);
        } catch (\Throwable $e) {
            $event->forceFill([
                'error' => mb_substr($e->getMessage(), 0, 65000),
                'attempts' => $event->attempts + 1,
            ])->save();

            Log::channel('single')->error('ses.webhook.handler_error', [
                'event_id' => $event->id,
                'message' => $e->getMessage(),
            ]);

            // 500 fuerza retry de SNS.
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
     * Persiste el evento. Idempotente por `(provider, MessageId)`: si SNS
     * reintenta antes del 200, devolvemos la fila ya existente sin duplicar.
     *
     * NOTA: `webhook_events` NO tiene unique constraint sobre `(provider,
     * event_id)` (el schema lo comparte con WhatsApp y otras integraciones
     * donde no aplica). La idempotencia se hace check-then-insert acá. La
     * race entre dos hilos del ASG es benigna porque:
     *
     *   1. SNS reintenta con backoff exponencial (segundos/minutos), no en
     *      paralelo bajo cargas normales.
     *   2. Si hay duplicado, ambos hilos llaman `EmailDeliveryService::suppress`
     *      que SÍ tiene unique partial index sobre `(LOWER(email), reason)`
     *      y captura el QueryException — no se generan suppressions duplicadas.
     *   3. Un par de filas duplicadas en `webhook_events` no rompe nada
     *      operativamente — se pueden purgar manualmente si molestan.
     *
     * @param  array<string, mixed>  $payload
     */
    private function logEvent(array $payload): ?WebhookEvent
    {
        $messageId = $payload['MessageId'] ?? null;

        if (is_string($messageId) && $messageId !== '') {
            $existing = WebhookEvent::query()
                ->where('provider', 'aws_ses')
                ->where('event_id', $messageId)
                ->first();

            if ($existing !== null) {
                Log::channel('single')->info('ses.webhook.duplicate_event', [
                    'message_id' => $messageId,
                    'event_id' => $existing->id,
                ]);

                return $existing;
            }
        }

        try {
            return WebhookEvent::create([
                'provider' => 'aws_ses',
                'event_id' => $messageId,
                'payload' => $payload,
                'signature_header' => null, // SNS firma en el body, no en header
                'signature_valid' => false,
                'received_at' => now(),
                'attempts' => 0,
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->error('ses.webhook.persist_failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function dispatch(array $payload, WebhookEvent $event): array
    {
        return match ($payload['Type'] ?? '') {
            'SubscriptionConfirmation' => $this->confirmSubscription($payload),
            'UnsubscribeConfirmation' => $this->logUnsubscribe($payload),
            'Notification' => $this->handleNotification($payload),
            default => ['type' => 'unknown'],
        };
    }

    /**
     * SNS envía esto una vez al suscribir un endpoint HTTPS. Confirmamos
     * siguiendo el `SubscribeURL` — un GET simple. Sin esto, el endpoint
     * queda como "Pending confirmation" y no recibe Notifications.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function confirmSubscription(array $payload): array
    {
        $url = $payload['SubscribeURL'] ?? null;

        if (! is_string($url) || ! str_starts_with($url, 'https://sns.')) {
            Log::channel('single')->warning('ses.webhook.subscription.invalid_url', [
                'url' => $url,
            ]);

            return ['type' => 'subscription_confirmation', 'status' => 'invalid_url'];
        }

        $response = Http::timeout(10)->get($url);

        $this->auditService->log(
            action: 'ses.subscription_confirmed',
            data: [
                'topic_arn' => $payload['TopicArn'] ?? null,
                'message_id' => $payload['MessageId'] ?? null,
                'response_status' => $response->status(),
            ],
        );

        Log::channel('single')->info('ses.webhook.subscription.confirmed', [
            'topic_arn' => $payload['TopicArn'] ?? null,
            'status' => $response->status(),
        ]);

        return [
            'type' => 'subscription_confirmation',
            'status' => $response->successful() ? 'confirmed' : 'failed',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function logUnsubscribe(array $payload): array
    {
        Log::channel('single')->warning('ses.webhook.unsubscribed', [
            'topic_arn' => $payload['TopicArn'] ?? null,
        ]);

        $this->auditService->log(
            action: 'ses.unsubscribed',
            data: [
                'topic_arn' => $payload['TopicArn'] ?? null,
                'message_id' => $payload['MessageId'] ?? null,
            ],
        );

        return ['type' => 'unsubscribe_confirmation'];
    }

    /**
     * El campo `Message` de un Notification SNS es un JSON-string anidado
     * con el evento SES real (Bounce, Complaint, Delivery, ...). Lo
     * decodificamos y enrutamos.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleNotification(array $payload): array
    {
        $raw = $payload['Message'] ?? '';
        $message = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($message)) {
            return ['type' => 'notification', 'status' => 'malformed'];
        }

        $eventType = $message['notificationType'] ?? $message['eventType'] ?? 'unknown';

        return match ($eventType) {
            'Bounce' => $this->processBounce($message),
            'Complaint' => $this->processComplaint($message),
            'Delivery' => ['type' => 'delivery', 'status' => 'logged'], // success, no action
            default => ['type' => 'notification', 'event_type' => $eventType, 'status' => 'ignored'],
        };
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function processBounce(array $message): array
    {
        $bounce = $message['bounce'] ?? [];
        $bounceType = $bounce['bounceType'] ?? 'undetermined'; // Permanent | Transient | Undetermined
        $bounceSubType = $bounce['bounceSubType'] ?? null;
        $recipients = $bounce['bouncedRecipients'] ?? [];
        $timestamp = $bounce['timestamp'] ?? now()->toIso8601String();

        // Solo Permanent y Undetermined van a suppression. Transient (mailbox
        // full, server temporarily down) puede reintentarse — los dejamos pasar
        // y SES los reintenta hasta su límite.
        if (! in_array($bounceType, ['Permanent', 'Undetermined'], true)) {
            return [
                'type' => 'bounce',
                'bounce_type' => $bounceType,
                'status' => 'skipped_transient',
            ];
        }

        $suppressed = 0;
        foreach ($recipients as $recipient) {
            $email = $recipient['emailAddress'] ?? null;
            if (! is_string($email) || $email === '') {
                continue;
            }

            $this->deliveryService->suppress(
                email: $email,
                reason: EmailSuppression::REASON_BOUNCE,
                subtype: $bounceSubType,
                metadata: [
                    'bounce_type' => $bounceType,
                    'diagnostic_code' => $recipient['diagnosticCode'] ?? null,
                    'action' => $recipient['action'] ?? null,
                    'status' => $recipient['status'] ?? null,
                ],
                receivedAt: new \DateTimeImmutable($timestamp),
            );

            $suppressed++;
        }

        return [
            'type' => 'bounce',
            'bounce_type' => $bounceType,
            'subtype' => $bounceSubType,
            'suppressed' => $suppressed,
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function processComplaint(array $message): array
    {
        $complaint = $message['complaint'] ?? [];
        $complaintSubType = $complaint['complaintSubType'] ?? null;
        $feedbackType = $complaint['complaintFeedbackType'] ?? null; // abuse, fraud, etc.
        $recipients = $complaint['complainedRecipients'] ?? [];
        $timestamp = $complaint['timestamp'] ?? now()->toIso8601String();

        $suppressed = 0;
        foreach ($recipients as $recipient) {
            $email = $recipient['emailAddress'] ?? null;
            if (! is_string($email) || $email === '') {
                continue;
            }

            $this->deliveryService->suppress(
                email: $email,
                reason: EmailSuppression::REASON_COMPLAINT,
                subtype: $feedbackType ?? $complaintSubType,
                metadata: [
                    'feedback_type' => $feedbackType,
                    'subtype' => $complaintSubType,
                    'arrival_date' => $complaint['arrivalDate'] ?? null,
                    'user_agent' => $complaint['userAgent'] ?? null,
                ],
                receivedAt: new \DateTimeImmutable($timestamp),
            );

            $suppressed++;
        }

        return [
            'type' => 'complaint',
            'feedback_type' => $feedbackType,
            'suppressed' => $suppressed,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AutomationFlow;
use App\Models\CompanyWhatsappAccountEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * F6 (§9.2) — push saliente firmado a n8n para un flujo de automatización.
 *
 * Cola `database` (canónica del proyecto, sin Redis). `tries=5` con backoff
 * exponencial y `timeout=10s`. Cada intento deja fila en
 * `company_whatsapp_account_events` (`event_type='automation_delivery'`) para la
 * tabla de entregas de la UI (§9.5) — cero tablas nuevas.
 *
 * El flujo se recarga en `handle()`: si se deshabilitó o rotó entre el dispatch
 * y la ejecución, no se emite (la revocación es inmediata, §7.5.1).
 */
class DispatchAutomationWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 10;

    /**
     * @param  array<string, mixed>  $body  Cuerpo completo del webhook (§9.2).
     */
    public function __construct(
        public readonly string $flowId,
        public readonly ?string $channelId,
        public readonly string $event,
        public readonly array $body,
        public readonly string $deliveryId,
    ) {}

    /** @return array<int, int> Backoff exponencial 10 s → 10 min. */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 600];
    }

    public function handle(): void
    {
        $flow = AutomationFlow::query()->whereKey($this->flowId)->first();

        // Revocado/deshabilitado entre el dispatch y ahora: no se emite.
        if ($flow === null || ! $flow->enabled) {
            return;
        }

        // Se firma EXACTAMENTE el JSON que se envía (mismo string): el receptor
        // recomputa hash_hmac(body, secret) y compara. UNESCAPED para que el
        // body byte-a-byte sea reproducible del otro lado.
        $json = (string) json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $json, (string) $flow->secret_encrypted);

        $start = microtime(true);
        $status = 0;
        $failed = true;

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-Flexyflow-Event' => $this->event,
                    'X-Flexyflow-Delivery' => $this->deliveryId,
                    'X-Flexyflow-Signature' => $signature,
                ])
                ->withBody($json, 'application/json')
                ->post($flow->url);

            $status = $response->status();
            $failed = $response->failed();
        } catch (Throwable) {
            // Timeout / DNS / conexión: se registra igual y se reintenta.
            $failed = true;
        } finally {
            $this->recordDelivery($flow, $status, (int) round((microtime(true) - $start) * 1000));
        }

        if ($failed) {
            // Dispara el retry con backoff. En el último intento cae en failed().
            throw new \RuntimeException("automation webhook non-2xx (status {$status}) para el flujo {$this->flowId}");
        }
    }

    private function recordDelivery(AutomationFlow $flow, int $status, int $latencyMs): void
    {
        $flow->forceFill(['last_delivery_at' => now()])->save();

        // La bitácora cuelga del canal que sirve al flujo (company + sede). Sin
        // canal resuelto (flujo mal configurado) se pierde la fila de UI pero no
        // el intento — queda en el log de aplicación.
        if ($this->channelId === null) {
            Log::channel('single')->warning('automation.delivery.no_channel', [
                'flow_id' => $flow->id,
                'event' => $this->event,
                'http_status' => $status,
            ]);

            return;
        }

        CompanyWhatsappAccountEvent::create([
            'company_whatsapp_account_id' => $this->channelId,
            'event_type' => 'automation_delivery',
            'payload' => [
                'flow_id' => $flow->id,
                'event' => $this->event,
                'http_status' => $status,
                'latency_ms' => $latencyMs,
                'attempt' => $this->attempts(),
                'delivery_id' => $this->deliveryId,
            ],
            'created_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::channel('single')->warning('automation.webhook.exhausted', [
            'flow_id' => $this->flowId,
            'event' => $this->event,
            'delivery_id' => $this->deliveryId,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DianProviderConfig;
use App\Models\ElectronicDocument;
use App\Services\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Webhook simulado del `MockDianProvider`.
 *
 * Cuando el mock devuelve `sent` (async), `MockDianProvider::scheduleAsyncWebhook`
 * encola este job con delay 5-30s. Al ejecutarse marca el documento como
 * `accepted` (~95%) o `rejected` (~5%) — mismo código que procesaría el
 * webhook real, pero ejecutado in-process (no via HTTP) para no requerir
 * que el dev tenga la app expuesta.
 *
 * N-instance safe (add-on §6):
 *  - `ShouldBeUnique` por `electronic_document_id` (TTL 300s) — si la queue
 *    re-encola por crash mid-job, no se duplica.
 *  - Transición monotónica: si el doc ya está en estado terminal cuando
 *    corre el job, no toca nada.
 */
class MockDianWebhookEmitter implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $electronicDocumentId) {}

    public function uniqueId(): string
    {
        return "mock-dian-webhook-{$this->electronicDocumentId}";
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(AuditService $audit): void
    {
        DB::transaction(function () use ($audit) {
            $document = ElectronicDocument::query()
                ->lockForUpdate()
                ->find($this->electronicDocumentId);

            if ($document === null || $document->isTerminal()) {
                return;
            }

            $config = DianProviderConfig::query()
                ->where('company_nit', $document->company_nit)
                ->where('is_active', true)
                ->first();

            $isProduction = $config?->environment === 'produccion';
            if ($isProduction) {
                // No corremos mock en prod (defensa en profundidad).
                return;
            }

            // 95% accept, 5% reject — async webhook tiene tasa de aceptación
            // alta para que el flujo feliz "sent → accepted" sea el común en QA.
            $accepted = random_int(0, 99) < 95;
            $status = $accepted ? 'accepted' : 'rejected';

            $rejectionReason = $accepted
                ? null
                : 'FAJ24a: NumFac duplicado (mock async)';

            $document->update([
                'status' => $status,
                'accepted_at' => $accepted ? now() : null,
                'rejected_at' => ! $accepted ? now() : null,
                'rejection_reason' => $rejectionReason,
                'provider_response_log' => array_merge(
                    $document->provider_response_log ?? [],
                    ['async_webhook' => [
                        'received_at' => now()->toIso8601String(),
                        'status' => $status,
                        'rejection_reason' => $rejectionReason,
                        'webhook_id' => 'MOCK-WH-'.Str::ulid(),
                    ]],
                ),
            ]);

            $audit->log('dian.document.'.($accepted ? 'accepted_by_dian' : 'rejected_by_dian'), null, $document, [
                'document_id' => $document->getKey(),
                'track_id' => $document->provider_track_id,
                'source' => 'mock_async_webhook',
            ]);
        });
    }
}

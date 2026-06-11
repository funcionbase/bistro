<?php

declare(strict_types=1);

namespace App\Services\Dian\Providers;

use App\Jobs\MockDianWebhookEmitter;
use App\Models\DianProviderConfig;
use App\Models\ElectronicDocument;
use App\Services\Dian\Contracts\DianProviderContract;
use App\Services\Dian\DTOs\DocumentDto;
use App\Services\Dian\DTOs\ProviderResponse;
use App\Services\Dian\Exceptions\MockInProductionException;
use Illuminate\Support\Str;

/**
 * Provider DIAN mock — comportamiento indistinguible de uno real para
 * APIs/UI/jobs, pero las respuestas se computan localmente.
 *
 * Garantías:
 *  - Genera `track_id` único por documento (`MOCK-{ULID}`).
 *  - Latencia simulada en rango configurado (`config('dian.mock.latency_ms_range')`).
 *  - Distribución de status por probabilidades configuradas: accepted /
 *    rejected / sent (async) / error. Determinístico por document id
 *    (mismo doc → mismo resultado en reintentos).
 *  - Razones de rechazo del catálogo real DIAN
 *    (`config('dian.mock.rejection_reasons_catalog')`).
 *  - En modo `sent` (async) encola `MockDianWebhookEmitter` con delay
 *    aleatorio 5-30s para simular el webhook real.
 *  - Guardarrail: si `environment=produccion` lanza excepción.
 */
class MockDianProvider implements DianProviderContract
{
    public function slug(): string
    {
        return 'mock';
    }

    public function send(DocumentDto $dto, DianProviderConfig $config): ProviderResponse
    {
        $this->guardProduction($config);

        $startedAt = microtime(true);
        $latencyRange = (array) config('dian.mock.latency_ms_range', [150, 800]);
        $latencyMs = random_int((int) $latencyRange[0], (int) $latencyRange[1]);
        usleep($latencyMs * 1000);

        $trackId = 'MOCK-'.Str::ulid()->toString();
        $seed = $this->seedFromDto($dto);
        $status = $this->pickStatus($seed);

        $rejectionReason = null;
        $rejectionCode = null;
        if ($status === 'rejected') {
            $catalog = (array) config('dian.mock.rejection_reasons_catalog', []);
            $rejectionCode = array_keys($catalog)[abs(crc32($seed.'-rej')) % max(count($catalog), 1)] ?? 'FAB01';
            $rejectionReason = $rejectionCode.': '.($catalog[$rejectionCode] ?? 'Razón mock');
        }

        $log = [
            'http_status' => $status === 'error' ? 504 : 200,
            'request_at' => now()->toIso8601String(),
            'response_at' => now()->addMilliseconds($latencyMs)->toIso8601String(),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'mock_seed' => $seed,
            'provider_payload' => [
                'documentNumber' => $dto->fullNumber,
                'environment' => $dto->environment,
                'status' => $status,
                'trackId' => $trackId,
                'rejectionCode' => $rejectionCode,
            ],
        ];

        return new ProviderResponse(
            status: $status,
            trackId: $trackId,
            rejectionReason: $rejectionReason,
            log: $log,
        );
    }

    public function retry(ElectronicDocument $document, DianProviderConfig $config): ProviderResponse
    {
        $this->guardProduction($config);

        // En reintento aumentamos la probabilidad de aceptación para que el
        // owner pueda salir del estado rejected al volver a intentar (mock-only).
        $latencyRange = (array) config('dian.mock.latency_ms_range', [150, 800]);
        $latencyMs = random_int((int) $latencyRange[0], (int) $latencyRange[1]);
        usleep($latencyMs * 1000);

        $trackId = (string) ($document->provider_track_id ?? 'MOCK-'.Str::ulid()->toString());
        $accepted = (random_int(0, 99) < 90);
        $status = $accepted ? 'accepted' : 'rejected';

        $rejectionReason = $accepted ? null : 'FAB01: Estructura UBL inválida (mock retry)';

        return new ProviderResponse(
            status: $status,
            trackId: $trackId,
            rejectionReason: $rejectionReason,
            log: [
                'retry' => true,
                'retry_count' => $document->retry_count + 1,
                'latency_ms' => $latencyMs,
                'response_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * Llamado por `DianDispatchService` después de persistir un documento
     * que el provider devolvió como `sent` (async). Encola el webhook
     * simulado con delay aleatorio.
     */
    public function scheduleAsyncWebhook(ElectronicDocument $document): void
    {
        $range = (array) config('dian.mock.async_delay_seconds_range', [5, 30]);
        $delay = random_int((int) $range[0], (int) $range[1]);

        MockDianWebhookEmitter::dispatch($document->getKey())->delay(now()->addSeconds($delay));
    }

    private function guardProduction(DianProviderConfig $config): void
    {
        if ($config->environment === 'produccion') {
            throw new MockInProductionException((string) $config->company_nit);
        }
    }

    private function seedFromDto(DocumentDto $dto): string
    {
        return hash('sha256', $dto->fullNumber.'|'.$dto->issuerNit.'|'.$dto->issuedAt->format('Y-m-d H:i:s'));
    }

    private function pickStatus(string $seed): string
    {
        // Distribución determinística por seed → mismo doc → mismo status.
        $bucket = abs(crc32($seed)) % 10000;

        $accept = (int) round(((float) config('dian.mock.accept_rate', 0.92)) * 10000);
        $reject = (int) round(((float) config('dian.mock.reject_rate', 0.05)) * 10000);
        $async = (int) round(((float) config('dian.mock.async_rate', 0.02)) * 10000);

        if ($bucket < $accept) {
            return 'accepted';
        }
        if ($bucket < $accept + $reject) {
            return 'rejected';
        }
        if ($bucket < $accept + $reject + $async) {
            return 'sent';
        }

        return 'error';
    }
}

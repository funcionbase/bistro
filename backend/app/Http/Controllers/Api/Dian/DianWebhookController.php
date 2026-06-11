<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Dian;

use App\Http\Controllers\Controller;
use App\Models\DianProviderConfig;
use App\Models\ElectronicDocument;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Webhook unificado del proveedor DIAN.
 *
 * URL: POST /api/v1/webhooks/dian/{provider} (whitelisted en
 * NormalizeStrings — el body firmado HMAC no debe normalizarse).
 *
 * Defensas (add-on §4):
 *  - HMAC SHA-256 con `webhook_secret_encrypted` de la empresa.
 *  - Cache::lock por (provider, track_id) — 2 hits paralelos → uno procesa,
 *    otro responde 202.
 *  - DB::transaction + lockForUpdate sobre la fila.
 *  - Transición monotónica: si el doc ya está accepted/rejected, retorna 200
 *    sin tocar.
 *
 * Encuentra la empresa por `provider_track_id`. Si no encuentra → 404
 * silencioso (no leak de información).
 */
class DianWebhookController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function handle(Request $request, string $provider): Response|JsonResponse
    {
        // Provider ya viene whitelisted por regex en routes/api.php (mock|factura1|siigo).
        // Defensa en profundidad: confirmamos contra config si el binding existe.
        $allowedProviders = ['mock', 'factura1', 'siigo'];
        if (! in_array($provider, $allowedProviders, true)) {
            return response()->noContent(404);
        }

        $payload = $request->json()->all();

        $trackId = (string) ($payload['track_id'] ?? $payload['trackId'] ?? '');
        $status = (string) ($payload['status'] ?? '');

        if ($trackId === '' || ! in_array($status, ['accepted', 'rejected'], true)) {
            return response()->json(['error' => 'invalid_payload'], 422);
        }

        $document = ElectronicDocument::query()
            ->where('provider_track_id', $trackId)
            ->where('provider_slug', $provider)
            ->first();

        if ($document === null) {
            // 404 silencioso — no revelamos si el track_id existe en otra empresa.
            return response()->noContent(404);
        }

        $config = DianProviderConfig::query()
            ->where('company_nit', $document->company_nit)
            ->where('is_active', true)
            ->first();

        if ($config === null) {
            return response()->noContent(404);
        }

        if (! $this->verifySignature($request, $config->webhook_secret_encrypted ?? '')) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $lockKey = "dian:webhook:{$provider}:{$trackId}";
        $ttl = (int) config('dian.webhook_lock_ttl_seconds', 60);

        if (! Cache::lock($lockKey, $ttl)->get()) {
            return response()->noContent(202);
        }

        try {
            DB::transaction(function () use ($document, $status, $payload, $trackId) {
                $fresh = ElectronicDocument::query()->lockForUpdate()->find($document->id);

                if ($fresh === null || $fresh->isTerminal()) {
                    return;
                }

                $fresh->update([
                    'status' => $status,
                    'accepted_at' => $status === 'accepted' ? now() : null,
                    'rejected_at' => $status === 'rejected' ? now() : null,
                    'rejection_reason' => $payload['rejection_reason'] ?? null,
                    'provider_response_log' => array_merge(
                        $fresh->provider_response_log ?? [],
                        ['webhook' => $payload, 'received_at' => now()->toIso8601String()]
                    ),
                ]);

                $this->audit->log("dian.document.{$status}_by_dian", null, $fresh, [
                    'track_id' => $trackId,
                    'source' => 'webhook',
                ]);
            });
        } finally {
            Cache::lock($lockKey, $ttl)->release();
        }

        return response()->noContent(200);
    }

    private function verifySignature(Request $request, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $providedSignature = (string) $request->header('X-Dian-Signature', '');
        if ($providedSignature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $providedSignature);
    }
}

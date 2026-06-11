<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Dian;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dian\UpdateDianProviderConfigRequest;
use App\Http\Resources\Dian\DianProviderConfigResource;
use App\Models\DianProviderConfig;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Configuración del proveedor DIAN activo.
 *
 * - GET: enmascara secretos (booleans `has_*`).
 * - PUT: PUT idempotente — actualiza la fila activa o crea una nueva.
 *   Las credenciales viajan en plaintext en el body (HTTPS); se persisten
 *   con cast `encrypted` en BD. webhook_secret se autogenera si viene vacío.
 */
class DianProviderConfigController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        $config = DianProviderConfig::query()->forCompany($nit)->active()->first();

        if ($config === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => DianProviderConfigResource::make($config)]);
    }

    public function update(UpdateDianProviderConfigRequest $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        $payload = $request->validated();

        $config = DB::transaction(function () use ($nit, $payload) {
            $existing = DianProviderConfig::query()
                ->where('company_nit', $nit)
                ->where('is_active', true)
                ->first();

            if ($existing !== null) {
                $existing->update(['is_active' => false]);
            }

            return DianProviderConfig::query()->create([
                'company_nit' => $nit,
                'provider_slug' => $payload['provider_slug'],
                'api_base_url' => $payload['api_base_url'] ?? null,
                'api_token_encrypted' => $payload['api_token'] ?? null,
                'software_id' => $payload['software_id'] ?? null,
                'software_pin_encrypted' => $payload['software_pin'] ?? null,
                'test_set_id' => $payload['test_set_id'] ?? null,
                'environment' => $payload['environment'],
                'webhook_secret_encrypted' => $payload['webhook_secret'] ?? Str::random(48),
                'is_active' => true,
            ]);
        });

        $this->audit->log('dian.provider.updated', null, $config, [
            'provider_slug' => $config->provider_slug,
            'environment' => $config->environment,
            'rotated_secrets' => array_keys(array_filter($payload, fn ($v, $k) => $v !== null && str_contains($k, 'token'), ARRAY_FILTER_USE_BOTH)),
        ]);

        return response()->json(['data' => DianProviderConfigResource::make($config)]);
    }
}

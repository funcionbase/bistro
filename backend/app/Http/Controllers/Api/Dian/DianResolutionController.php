<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Dian;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dian\StoreDianResolutionRequest;
use App\Http\Requests\Dian\UpdateDianResolutionRequest;
use App\Http\Resources\Dian\DianResolutionResource;
use App\Models\DianResolution;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Resoluciones DIAN registradas por la empresa.
 *
 * - GET: lista todas (activas + históricas) para el owner.
 * - POST: registra resolución nueva. Si ya hay una activa para
 *   (company_nit, document_type, environment), se marca como is_active=false
 *   ANTES de insertar la nueva (paper trail, evita conflicto con el UNIQUE
 *   parcial de BD). Todo dentro de DB::transaction.
 * - PUT: solo permite vigencia / is_active. Para cambiar prefijo/rango/key
 *   se registra nueva resolución (DIAN auditable).
 */
class DianResolutionController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');

        $resolutions = DianResolution::query()
            ->forCompany($nit)
            ->orderByDesc('is_active')
            ->orderByDesc('valid_until')
            ->get();

        return response()->json([
            'data' => DianResolutionResource::collection($resolutions),
        ]);
    }

    public function store(StoreDianResolutionRequest $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        $payload = $request->validated();

        $resolution = DB::transaction(function () use ($nit, $payload) {
            // Si hay activa, la marcamos histórica para no chocar con el
            // UNIQUE parcial (company_nit, document_type, environment) WHERE is_active.
            DianResolution::query()
                ->where('company_nit', $nit)
                ->where('document_type', $payload['document_type'])
                ->where('environment', $payload['environment'])
                ->where('is_active', true)
                ->update(['is_active' => false]);

            return DianResolution::query()->create(array_merge($payload, [
                'company_nit' => $nit,
                'current_number' => $payload['range_from'] - 1,
                'is_active' => (bool) ($payload['is_active'] ?? true),
            ]));
        });

        $this->audit->log('dian.resolution.created', null, $resolution, [
            'resolution_number' => $resolution->resolution_number,
            'prefix' => $resolution->prefix,
            'range' => "{$resolution->range_from}-{$resolution->range_to}",
            'environment' => $resolution->environment,
        ]);

        return response()->json(['data' => DianResolutionResource::make($resolution)], 201);
    }

    public function update(UpdateDianResolutionRequest $request, DianResolution $resolution): JsonResponse
    {
        $this->ensureSameCompany($request, $resolution);
        $payload = $request->validated();

        $resolution->update($payload);

        $this->audit->log('dian.resolution.updated', null, $resolution, [
            'changed_fields' => array_keys($payload),
        ]);

        return response()->json(['data' => DianResolutionResource::make($resolution->refresh())]);
    }

    public function destroy(Request $request, DianResolution $resolution): JsonResponse
    {
        $this->ensureSameCompany($request, $resolution);

        $resolution->update(['is_active' => false]);

        $this->audit->log('dian.resolution.deactivated', null, $resolution);

        return response()->json([], 204);
    }

    private function ensureSameCompany(Request $request, DianResolution $resolution): void
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        abort_unless($resolution->company_nit === $nit, 404);
    }
}

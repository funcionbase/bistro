<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreKdsStationRequest;
use App\Http\Requests\Company\UpdateKdsStationRequest;
use App\Models\KdsStation;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CRUD de estaciones KDS.
 *
 * Listar es lo único accesible a roles operativos (selector del editor de
 * menú); store/update/archive requiere permiso `kds.manage_stations`
 * (definido en F7). Hasta ese momento se permite con `company.update,update`
 * que ya está en el catálogo.
 *
 * BranchScope global filtra por sede activa del request. company_nit lo
 * inyecta `EnsureCompanyAccess`.
 */
class KdsStationController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * GET /api/v1/kds/stations — lista estaciones activas de la sede.
     */
    public function index(Request $request): JsonResponse
    {
        $stations = KdsStation::query()
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $stations->map(fn (KdsStation $s) => $this->toArray($s))->values(),
        ]);
    }

    /**
     * POST /api/v1/company/kds/stations — crea estación.
     */
    public function store(StoreKdsStationRequest $request): JsonResponse
    {
        $companyNit = (string) $request->attributes->get('active_company_nit');
        $branchId = (string) $request->attributes->get('active_branch_id');
        $data = $request->validated();
        $actor = $this->resolveActor($request);

        $station = DB::transaction(function () use ($companyNit, $branchId, $data) {
            if (($data['is_default'] ?? false) === true) {
                KdsStation::query()
                    ->where('company_nit', $companyNit)
                    ->where('branch_id', $branchId)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            return KdsStation::create([
                'company_nit' => $companyNit,
                'branch_id' => $branchId,
                'slug' => $data['slug'],
                'name' => $data['name'],
                'color' => strtoupper($data['color']),
                'sla_warn_minutes' => $data['sla_warn_minutes'],
                'sla_alert_minutes' => $data['sla_alert_minutes'],
                'is_default' => (bool) ($data['is_default'] ?? false),
            ]);
        });

        $this->audit->log(
            'kds.station.created',
            user: $actor,
            auditable: $station,
            data: [
                'station_id' => $station->id,
                'slug' => $station->slug,
                'name' => $station->name,
            ],
            request: $request,
        );

        return response()->json(['data' => $this->toArray($station->fresh())], 201);
    }

    /**
     * PATCH /api/v1/company/kds/stations/{id} — actualiza.
     */
    public function update(UpdateKdsStationRequest $request, string $id): JsonResponse
    {
        $station = $this->locateOrFail($request, $id);
        $before = $station->only(['name', 'color', 'sla_warn_minutes', 'sla_alert_minutes', 'is_default']);
        $data = $request->validated();
        $actor = $this->resolveActor($request);

        DB::transaction(function () use ($station, $data) {
            if (array_key_exists('is_default', $data) && $data['is_default'] === true) {
                KdsStation::query()
                    ->where('company_nit', $station->company_nit)
                    ->where('branch_id', $station->branch_id)
                    ->where('id', '!=', $station->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            if (array_key_exists('color', $data)) {
                $data['color'] = strtoupper($data['color']);
            }

            $station->fill($data)->save();
        });

        $this->audit->log(
            'kds.station.updated',
            user: $actor,
            auditable: $station,
            data: [
                'station_id' => $station->id,
                'before' => $before,
                'after' => $station->fresh()->only(array_keys($before)),
            ],
            request: $request,
        );

        return response()->json(['data' => $this->toArray($station->fresh())]);
    }

    /**
     * POST /api/v1/company/kds/stations/{id}/archive — soft-archive.
     *
     * Guard: no permite archivar la estación `is_default=true` si es la
     * última activa de la sede — los items de menú sin mapeo necesitan
     * un fallback. El owner debe primero marcar otra estación como default
     * o crear una nueva.
     */
    public function archive(Request $request, string $id): JsonResponse
    {
        $station = $this->locateOrFail($request, $id);
        $actor = $this->resolveActor($request);

        if ($station->isArchived()) {
            return response()->json(['data' => $this->toArray($station)]);
        }

        // Si la estación es la única default activa de la sede,
        // bloquear el archive. Sin default, los items sin `kds_station_id`
        // en su categoría desaparecen del KDS (cocina ciega).
        if ($station->is_default) {
            $otherActiveDefault = KdsStation::query()
                ->where('company_nit', $station->company_nit)
                ->where('branch_id', $station->branch_id)
                ->where('id', '!=', $station->id)
                ->where('is_default', true)
                ->whereNull('archived_at')
                ->exists();

            if (! $otherActiveDefault) {
                return response()->json([
                    'message' => 'No se puede archivar la estación default. Marca otra estación como default antes.',
                ], 422);
            }
        }

        DB::transaction(function () use ($station) {
            $station->archived_at = Carbon::now();
            $station->is_default = false;
            $station->save();
        });

        $this->audit->log(
            'kds.station.archived',
            user: $actor,
            auditable: $station,
            data: [
                'station_id' => $station->id,
                'slug' => $station->slug,
            ],
            request: $request,
        );

        return response()->json(['data' => $this->toArray($station->fresh())]);
    }

    private function locateOrFail(Request $request, string $id): KdsStation
    {
        $companyNit = (string) $request->attributes->get('active_company_nit');
        $branchId = (string) $request->attributes->get('active_branch_id');

        return KdsStation::query()
            ->whereKey($id)
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->firstOrFail();
    }

    private function resolveActor(Request $request): User
    {
        $userId = (string) ($request->attributes->get('jwt_payload')['sub'] ?? '');

        return User::query()->findOrFail($userId);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(KdsStation $s): array
    {
        return [
            'id' => $s->id,
            'slug' => $s->slug,
            'name' => $s->name,
            'color' => $s->color,
            'sla_warn_minutes' => $s->sla_warn_minutes,
            'sla_alert_minutes' => $s->sla_alert_minutes,
            'is_default' => $s->is_default,
            'archived_at' => optional($s->archived_at)?->toIso8601String(),
        ];
    }
}

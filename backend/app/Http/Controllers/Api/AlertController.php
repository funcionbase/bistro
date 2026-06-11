<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlertEventResource;
use App\Models\AlertEvent;
use App\Rules\SafePlainText;
use App\Services\AuditService;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Feed de alertas accionables (#124).
 *
 * Gate por `reports.read` — mismo permiso que protege food cost y márgenes,
 * para que cajeros sin acceso a info financiera no vean alertas que la
 * exponen indirectamente.
 *
 * Las mutaciones (dismiss/action) son trazables vía AuditService y se hacen
 * dentro de DB::transaction + lockForUpdate sobre el evento para evitar dos
 * usuarios manejando la misma alerta simultáneamente.
 */
class AlertController extends Controller
{
    use ResolvesJwtActor;

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly AuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'reports', 'read');

        $companyNit = (string) $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['active', 'dismissed', 'actioned', 'all'])],
            'severity' => ['nullable', 'string', Rule::in([AlertEvent::SEVERITY_INFO, AlertEvent::SEVERITY_WARNING, AlertEvent::SEVERITY_CRITICAL])],
            'type' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $validated['status'] ?? 'active';

        $query = AlertEvent::query()->forCompany($companyNit);

        match ($status) {
            'active' => $query->active(),
            'dismissed' => $query->dismissed(),
            'actioned' => $query->actioned(),
            default => null,
        };

        if (! empty($validated['severity'])) {
            $query->where('severity', $validated['severity']);
        }
        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $paginator = $query
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('triggered_at')
            ->paginate(perPage: (int) ($validated['per_page'] ?? 25), page: (int) ($validated['page'] ?? 1));

        return response()->json([
            'data' => AlertEventResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'reports', 'read');

        $companyNit = (string) $request->attributes->get('active_company_nit');

        $counts = AlertEvent::query()
            ->forCompany($companyNit)
            ->active()
            ->selectRaw('severity, COUNT(*) AS total')
            ->groupBy('severity')
            ->pluck('total', 'severity');

        return response()->json([
            'data' => [
                'active_total' => (int) $counts->sum(),
                'by_severity' => [
                    AlertEvent::SEVERITY_CRITICAL => (int) ($counts[AlertEvent::SEVERITY_CRITICAL] ?? 0),
                    AlertEvent::SEVERITY_WARNING => (int) ($counts[AlertEvent::SEVERITY_WARNING] ?? 0),
                    AlertEvent::SEVERITY_INFO => (int) ($counts[AlertEvent::SEVERITY_INFO] ?? 0),
                ],
            ],
        ]);
    }

    public function dismiss(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'reports', 'read');

        $actor = $this->actingUserOrFail($request);
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $event = DB::transaction(function () use ($id, $companyNit) {
            $event = AlertEvent::query()
                ->where('id', $id)
                ->where('company_nit', $companyNit)
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->dismissed_at === null && $event->actioned_at === null) {
                $event->update(['dismissed_at' => now()]);
            }

            return $event->fresh();
        });

        $this->auditService->log('alert.dismissed', $actor, $event, [
            'type' => $event->type,
            'severity' => $event->severity,
            'target' => "{$event->target_type}:{$event->target_id}",
        ]);

        return response()->json(['data' => (new AlertEventResource($event))->resolve($request)]);
    }

    public function action(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'reports', 'read');

        $actor = $this->actingUserOrFail($request);
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'note' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ]);

        $event = DB::transaction(function () use ($id, $companyNit, $validated, $actor) {
            $event = AlertEvent::query()
                ->where('id', $id)
                ->where('company_nit', $companyNit)
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->actioned_at === null) {
                $event->update([
                    'actioned_at' => now(),
                    'actioned_note' => $validated['note'] ?? null,
                    'actioned_by' => $actor->id,
                ]);
            }

            return $event->fresh();
        });

        $this->auditService->log('alert.actioned', $actor, $event, [
            'type' => $event->type,
            'severity' => $event->severity,
            'target' => "{$event->target_type}:{$event->target_id}",
            'note' => $validated['note'] ?? null,
        ]);

        return response()->json(['data' => (new AlertEventResource($event))->resolve($request)]);
    }
}

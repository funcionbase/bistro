<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Models\AlertRule;
use App\Services\Alerts\AlertSeedService;
use App\Services\AuditService;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD ligero de reglas de alerta.
 *
 * En v1 hay UNA fila por (company_nit, type). El endpoint PUT actúa como
 * upsert: si la regla no existe se crea con defaults; si existe se actualiza.
 * No hay DELETE — sólo `enabled=false` para desactivar.
 *
 * Gate: `company.update` (mismo permiso que /company/preferences).
 */
class AlertRuleController extends Controller
{
    use ResolvesJwtActor;

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly AlertSeedService $seedService,
        private readonly AuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'reports', 'read');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $this->seedService->ensureDefaults($companyNit);

        $rules = AlertRule::query()
            ->forCompany($companyNit)
            ->orderBy('type')
            ->get()
            ->map(fn (AlertRule $rule) => [
                'id' => $rule->id,
                'type' => $rule->type,
                'threshold' => (float) $rule->threshold,
                'period_days' => (int) $rule->period_days,
                'enabled' => (bool) $rule->enabled,
                'notify_dashboard' => (bool) $rule->notify_dashboard,
                'notify_whatsapp' => (bool) $rule->notify_whatsapp,
            ]);

        return response()->json(['data' => $rules]);
    }

    public function upsert(Request $request, string $type): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'company', 'update');

        if (! in_array($type, AlertRule::TYPES, true)) {
            abort(404, 'Tipo de alerta desconocido.');
        }

        $actor = $this->actingUserOrFail($request);
        $companyNit = (string) $request->attributes->get('active_company_nit');
        $this->seedService->ensureDefaults($companyNit);

        $validated = $request->validate([
            'threshold' => ['required', 'numeric', 'min:0', 'max:999999'],
            'period_days' => ['required', 'integer', 'min:1', 'max:365'],
            'enabled' => ['required', 'boolean'],
            'notify_dashboard' => ['nullable', 'boolean'],
            'notify_whatsapp' => ['nullable', 'boolean'],
        ]);

        $rule = AlertRule::query()
            ->where('company_nit', $companyNit)
            ->where('type', $type)
            ->firstOrFail();

        $before = $rule->only(['threshold', 'period_days', 'enabled', 'notify_dashboard', 'notify_whatsapp']);

        $rule->update([
            'threshold' => $validated['threshold'],
            'period_days' => $validated['period_days'],
            'enabled' => $validated['enabled'],
            'notify_dashboard' => $validated['notify_dashboard'] ?? $rule->notify_dashboard,
            'notify_whatsapp' => $validated['notify_whatsapp'] ?? $rule->notify_whatsapp,
        ]);

        $this->auditService->log('alert_rule.updated', $actor, $rule, [
            'type' => $rule->type,
            'before' => $before,
            'after' => $rule->only(['threshold', 'period_days', 'enabled', 'notify_dashboard', 'notify_whatsapp']),
        ]);

        return response()->json([
            'data' => [
                'id' => $rule->id,
                'type' => $rule->type,
                'threshold' => (float) $rule->threshold,
                'period_days' => (int) $rule->period_days,
                'enabled' => (bool) $rule->enabled,
                'notify_dashboard' => (bool) $rule->notify_dashboard,
                'notify_whatsapp' => (bool) $rule->notify_whatsapp,
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Employees;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Models\CompanyWorkforceSetting;
use App\Services\AuditService;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Configuración de jornada laboral por empresa (1:1).
 *
 * Si la fila no existe la creamos al primer GET. El flujo de enrollment
 * también la crea para empresas nuevas; este endpoint cubre el camino
 * defensivo.
 */
class WorkforceSettingsController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly AuditService $auditService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'workforce', 'read');
        $nit = $this->activeCompanyNit($request);

        $settings = CompanyWorkforceSetting::firstOrCreate(
            ['company_nit' => $nit],
            ['max_weekly_hours' => 48, 'min_days_off_per_week' => 1, 'hours_warning_mode' => 'warn']
        );

        return response()->json(['data' => $this->toArray($settings)]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'workforce', 'update');
        $nit = $this->activeCompanyNit($request);
        $actor = $this->actingUserOrFail($request);

        $data = $request->validate([
            'max_weekly_hours' => ['sometimes', 'integer', 'min:1', 'max:84'],
            'min_days_off_per_week' => ['sometimes', 'integer', 'min:0', 'max:7'],
            'hours_warning_mode' => ['sometimes', Rule::in(['warn', 'block', 'off'])],
        ]);

        $settings = CompanyWorkforceSetting::firstOrCreate(
            ['company_nit' => $nit],
            ['max_weekly_hours' => 48, 'min_days_off_per_week' => 1, 'hours_warning_mode' => 'warn']
        );

        $original = $settings->only(array_keys($data));
        $settings->fill($data)->save();

        $this->auditService->log('workforce.settings_updated', $actor, $settings, [
            'before' => $original,
            'after' => $data,
        ]);

        return response()->json(['data' => $this->toArray($settings)]);
    }

    /** @return array<string, mixed> */
    private function toArray(CompanyWorkforceSetting $s): array
    {
        return [
            'company_nit' => $s->company_nit,
            'max_weekly_hours' => $s->max_weekly_hours,
            'min_days_off_per_week' => $s->min_days_off_per_week,
            'hours_warning_mode' => $s->hours_warning_mode,
        ];
    }
}

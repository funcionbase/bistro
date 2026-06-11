<?php

namespace App\Http\Controllers\Api\Employees;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Models\EmployeePosition;
use App\Rules\SafePlainText;
use App\Services\AuditService;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Catálogo de cargos por empresa. Cargos del sistema (is_system=true) son
 * compartidos y NO se pueden borrar. Las empresas crean cargos custom con
 * `is_system=false, company_nit=X`.
 */
class EmployeePositionController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly AuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'employees', 'read');
        $nit = $this->activeCompanyNit($request);

        $positions = EmployeePosition::query()
            ->where(function ($q) use ($nit) {
                $q->whereNull('company_nit')->orWhere('company_nit', $nit);
            })
            ->orderByDesc('is_system')
            ->orderBy('label')
            ->get();

        return response()->json([
            'data' => $positions->map(fn (EmployeePosition $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'label' => $p->label,
                'color' => $p->color,
                'is_system' => $p->is_system,
            ])->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'employees', 'create');
        $nit = $this->activeCompanyNit($request);
        $actor = $this->actingUserOrFail($request);

        $data = $request->validate([
            'label' => ['required', new SafePlainText(maxBytes: 80, allowWhitespace: false)],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $slug = Str::slug($data['label']);

        $position = EmployeePosition::updateOrCreate(
            ['company_nit' => $nit, 'slug' => $slug],
            [
                'label' => $data['label'],
                'color' => $data['color'] ?? null,
                'is_system' => false,
            ]
        );

        $this->auditService->log('employee_position.created', $actor, $position);

        return response()->json([
            'data' => [
                'id' => $position->id,
                'slug' => $position->slug,
                'label' => $position->label,
                'color' => $position->color,
                'is_system' => false,
            ],
        ], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'employees', 'delete');
        $nit = $this->activeCompanyNit($request);
        $actor = $this->actingUserOrFail($request);

        $position = EmployeePosition::query()
            ->where('id', $id)
            ->where('company_nit', $nit)
            ->where('is_system', false)
            ->firstOrFail();

        $position->delete();
        $this->auditService->log('employee_position.deleted', $actor, $position);

        return response()->json(null, 204);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\GuardsOwnerOnlyPermissions;
use App\Http\Controllers\Controller;
use App\Models\CompanyRole;
use App\Models\CompanyUser;
use App\Models\User;
use App\Rules\SafePlainText;
use App\Services\AuditService;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * CRUD de roles personalizados por empresa.
 *
 * Solo usuarios con rol is_system=true (owner) pueden crear, editar o eliminar roles.
 * Los roles con is_system=true no pueden ser modificados ni eliminados (firstOrFail con where is_system=false).
 * Un rol con usuarios asignados no puede eliminarse (retorna 422).
 * Todas las operaciones de escritura se registran en auditoría.
 */
class RoleController extends Controller
{
    use GuardsOwnerOnlyPermissions;

    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request): JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');
        $jwtRole = $request->attributes->get('jwt_payload')['role'] ?? null;

        $roles = CompanyRole::where('company_nit', $companyNit)
            ->with('permissions.feature')
            ->withCount('users')
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $roles,
            'can_manage' => is_array($jwtRole) && ($jwtRole['is_system'] ?? false),
        ]);
    }

    public function store(Request $request): JsonResource
    {
        $this->authorizeManagerRole($request);

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $validated = $request->validate([
            'name' => ['required', new SafePlainText(maxBytes: 64, allowWhitespace: false)],
            'description' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'permissions' => ['required', 'array'],
            'permissions.*.feature_id' => ['required', 'uuid', 'exists:features,id'],
            'permissions.*.can_create' => ['boolean'],
            'permissions.*.can_read' => ['boolean'],
            'permissions.*.can_update' => ['boolean'],
            'permissions.*.can_delete' => ['boolean'],
        ]);

        $validated['permissions'] = $this->stripOwnerOnlyGrants($validated['permissions']);

        return DB::transaction(function () use ($companyNit, $validated, $jwtPayload, $request) {
            $role = CompanyRole::create([
                'company_nit' => $companyNit,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'color' => $validated['color'] ?? null,
                'is_system' => false,
            ]);
            foreach ($validated['permissions'] as $perm) {
                $role->permissions()->create([
                    'feature_id' => $perm['feature_id'],
                    'can_create' => $perm['can_create'] ?? false,
                    'can_read' => $perm['can_read'] ?? false,
                    'can_update' => $perm['can_update'] ?? false,
                    'can_delete' => $perm['can_delete'] ?? false,
                ]);
            }

            $actor = User::find($jwtPayload['sub'] ?? null);
            $this->auditService->log('role.created', $actor, $role, [
                'company_nit' => $companyNit,
                'name' => $role->name,
                'permissions' => $validated['permissions'],
            ], $request);

            return new JsonResource($role->load('permissions.feature'));
        });
    }

    public function update(Request $request, string $id): JsonResource
    {
        $this->authorizeManagerRole($request);

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $role = CompanyRole::where('company_nit', $companyNit)->where('id', $id)->where('is_system', false)->firstOrFail();
        $validated = $request->validate([
            'name' => ['required', new SafePlainText(maxBytes: 64, allowWhitespace: false)],
            'description' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'permissions' => ['required', 'array'],
            'permissions.*.feature_id' => ['required', 'uuid', 'exists:features,id'],
            'permissions.*.can_create' => ['boolean'],
            'permissions.*.can_read' => ['boolean'],
            'permissions.*.can_update' => ['boolean'],
            'permissions.*.can_delete' => ['boolean'],
        ]);

        $validated['permissions'] = $this->stripOwnerOnlyGrants($validated['permissions']);

        $before = [
            'name' => $role->name,
            'description' => $role->description,
            'color' => $role->color,
            'permissions' => $role->permissions()->with('feature:id,slug')->get()->toArray(),
        ];

        $resource = DB::transaction(function () use ($role, $validated, $before, $jwtPayload, $companyNit, $request) {
            $role->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'color' => $validated['color'] ?? null,
            ]);
            $role->permissions()->delete();
            foreach ($validated['permissions'] as $perm) {
                $role->permissions()->create([
                    'feature_id' => $perm['feature_id'],
                    'can_create' => $perm['can_create'] ?? false,
                    'can_read' => $perm['can_read'] ?? false,
                    'can_update' => $perm['can_update'] ?? false,
                    'can_delete' => $perm['can_delete'] ?? false,
                ]);
            }

            $after = [
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'color' => $validated['color'] ?? null,
                'permissions' => $validated['permissions'],
            ];

            $actor = User::find($jwtPayload['sub'] ?? null);
            $this->auditService->log('role.updated', $actor, $role, [
                'company_nit' => $companyNit,
                'before' => $before,
                'after' => $after,
            ], $request);

            return new JsonResource($role->load('permissions.feature'));
        });

        // Invalida la matriz cacheada de permisos de todos los usuarios de la
        // empresa — sin esto, quienes tienen este rol siguen con permisos viejos
        // hasta que vence el TTL del cache (FeaturePermissionService).
        FeaturePermissionService::forgetCompanyCache($companyNit);

        return $resource;
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeManagerRole($request);

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $role = CompanyRole::where('company_nit', $companyNit)->where('id', $id)->where('is_system', false)->firstOrFail();

        if (CompanyUser::where('company_role_id', $role->id)->exists()) {
            abort(422, 'No se puede eliminar un rol que tiene usuarios asignados.');
        }

        $snapshot = [
            'company_nit' => $companyNit,
            'name' => $role->name,
            'permissions' => $role->permissions()->with('feature:id,slug')->get()->toArray(),
        ];

        $role->permissions()->delete();
        $role->delete();

        $actor = User::find($jwtPayload['sub'] ?? null);
        $this->auditService->log('role.deleted', $actor, $role, $snapshot, $request);

        return response()->json(['deleted' => true]);
    }

    private function authorizeManagerRole(Request $request): void
    {
        $role = $request->attributes->get('jwt_payload')['role'] ?? null;
        if (! is_array($role) || ! ($role['is_system'] ?? false)) {
            abort(403, 'No autorizado.');
        }
    }
}

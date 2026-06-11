<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\GuardsOwnerOnlyPermissions;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\CompanyRole;
use App\Models\CompanyUser;
use App\Services\AuditService;
use App\Services\FeaturePermissionService;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Gestiona los miembros de una empresa: asignación de rol, permisos personalizados, estado y baja.
 *
 * update(): cambia el rol de un miembro; impide dejar sin rol de sistema a la empresa si hay un solo owner.
 * updatePermissions(): edita permisos del rol de un usuario; un actor no puede otorgar permisos que él no tiene.
 * updateStatus(): activa/desactiva el acceso; desactivar invalida el JWT activo del usuario.
 * destroy(): desvincula al usuario; impide eliminar al último member con rol is_system.
 * Invariante: ningún actor puede modificarse a sí mismo.
 */
class UserRoleController extends Controller
{
    use GuardsOwnerOnlyPermissions;

    public function __construct(
        private readonly AuditService $auditService,
        private readonly JwtService $jwtService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $jwtRole = $jwtPayload['role'] ?? null;
        $currentUserId = $jwtPayload['sub'] ?? null;

        $memberships = CompanyUser::where('company_nit', $companyNit)
            ->with(['user', 'role'])
            ->get();

        $actorPermissions = [];
        if ($currentUserId) {
            $actorMembership = CompanyUser::where('company_nit', $companyNit)
                ->where('user_id', $currentUserId)
                ->with('role.permissions')
                ->first();

            if ($actorMembership) {
                $actorPermissions = $actorMembership->role->permissions->toArray();
            }
        }

        // Multi-sede (#117): adjuntar las sedes asignadas a cada usuario para
        // mostrar la columna "Sedes" sin requerir N requests adicionales.
        $userIds = $memberships->pluck('user_id')->all();
        $branchAssignments = BranchUser::query()
            ->whereIn('user_id', $userIds)
            ->whereHas('branch', fn ($q) => $q->where('company_nit', $companyNit)->whereNull('archived_at'))
            ->get(['user_id', 'branch_id']);

        $branches = Branch::query()
            ->where('company_nit', $companyNit)
            ->whereNull('archived_at')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_default']);

        $byUser = $branchAssignments->groupBy('user_id');
        $memberships->each(function ($m) use ($byUser) {
            $m->setAttribute('branch_ids', $byUser->get($m->user_id)?->pluck('branch_id')->all() ?? []);
        });

        return response()->json([
            'data' => $memberships,
            'can_manage' => is_array($jwtRole) && ($jwtRole['is_system'] ?? false),
            'actor_permissions' => $actorPermissions,
            'branches' => $branches->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
                'is_default' => (bool) $b->is_default,
            ])->values()->all(),
        ]);
    }

    public function update(Request $request, string $id): JsonResource|JsonResponse
    {
        $this->authorizeManagerRole($request);

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $currentUserId = $jwtPayload['sub'] ?? null;

        $validated = $request->validate([
            'company_role_id' => ['required', 'uuid', 'exists:company_roles,id'],
        ]);

        $membership = CompanyUser::where('company_nit', $companyNit)
            ->where('user_id', $id)
            ->with('role')
            ->firstOrFail();

        if ((string) $membership->user_id === (string) $currentUserId) {
            abort(403, 'No puedes cambiar tu propio rol.');
        }

        $oldRole = $membership->role;
        $newRole = CompanyRole::where('company_nit', $companyNit)->findOrFail($validated['company_role_id']);

        $isOwnerOrAdmin = fn (CompanyRole $role): bool => (bool) $role->is_system;

        if ($isOwnerOrAdmin($oldRole) && ! $isOwnerOrAdmin($newRole)) {
            $remainingCount = CompanyUser::where('company_nit', $companyNit)
                ->where('company_role_id', $oldRole->id)
                ->count();

            if ($remainingCount <= 1) {
                abort(403, 'No puedes eliminar el último usuario con rol '.$oldRole->name.' de la empresa.');
            }
        }

        DB::transaction(function () use ($membership, $validated, $oldRole, $newRole, $request) {
            $membership->company_role_id = $validated['company_role_id'];
            $membership->save();

            $this->auditService->log(
                'user.role_changed',
                null,
                $membership->user,
                [
                    'company_nit' => $membership->company_nit,
                    'old_role' => $oldRole->name,
                    'new_role' => $newRole->name,
                ],
                $request
            );
        });

        // El usuario cambió de rol → su matriz de permisos cacheada quedó vieja.
        FeaturePermissionService::forgetCompanyCache($companyNit);

        return new JsonResource($membership->fresh(['user', 'role']));
    }

    public function updatePermissions(Request $request, string $id): JsonResource|JsonResponse
    {
        $this->authorizeManagerRole($request);

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $currentUserId = $jwtPayload['sub'] ?? null;

        $actorMembership = CompanyUser::where('company_nit', $companyNit)
            ->where('user_id', $currentUserId)
            ->with('role.permissions')
            ->firstOrFail();

        if ((string) $id === (string) $currentUserId) {
            abort(403, 'No puedes editar tus propios permisos.');
        }

        $targetMembership = CompanyUser::where('company_nit', $companyNit)
            ->where('user_id', $id)
            ->with('role.permissions')
            ->firstOrFail();

        $targetRole = $targetMembership->role;

        if ($targetRole->is_system) {
            abort(422, 'Los roles de sistema se editan via PUT /roles/{id}.');
        }

        $isActorOwner = $actorMembership->role->name === config('roles.role_names.owner');

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*.feature_id' => ['required', 'uuid', 'exists:features,id'],
            'permissions.*.can_create' => ['boolean'],
            'permissions.*.can_read' => ['boolean'],
            'permissions.*.can_update' => ['boolean'],
            'permissions.*.can_delete' => ['boolean'],
        ]);

        // Neutraliza grants de features owner-only (no asignables a roles
        // no-sistema) antes de la verificación de escalación y la persistencia.
        $validated['permissions'] = $this->stripOwnerOnlyGrants($validated['permissions']);

        if (! $isActorOwner) {
            $actorPermissions = $actorMembership->role->permissions;
            foreach ($validated['permissions'] as $permission) {
                $featureId = $permission['feature_id'];
                foreach (['can_create', 'can_read', 'can_update', 'can_delete'] as $bit) {
                    if (isset($permission[$bit]) && $permission[$bit]) {
                        $hasPermission = $actorPermissions
                            ->where('feature_id', $featureId)
                            ->where($bit, true)
                            ->isNotEmpty();

                        if (! $hasPermission) {
                            abort(422, 'No puedes otorgar permisos que tú mismo no posees.');
                        }
                    }
                }
            }
        }

        DB::transaction(function () use ($targetMembership, $validated, $request, $companyNit) {
            $before = $targetMembership->role->permissions
                ->map(fn ($p) => [
                    'feature_id' => $p->feature_id,
                    'can_create' => $p->can_create,
                    'can_read' => $p->can_read,
                    'can_update' => $p->can_update,
                    'can_delete' => $p->can_delete,
                ])
                ->all();

            $targetMembership->role->permissions()->delete();

            $permissionData = collect($validated['permissions'])
                ->map(fn ($p) => [
                    'feature_id' => $p['feature_id'],
                    'can_create' => $p['can_create'] ?? false,
                    'can_read' => $p['can_read'] ?? false,
                    'can_update' => $p['can_update'] ?? false,
                    'can_delete' => $p['can_delete'] ?? false,
                ])
                ->all();

            $targetMembership->role->permissions()->createMany($permissionData);

            $this->auditService->log(
                'user.permissions_updated',
                null,
                $targetMembership->user,
                [
                    'company_nit' => $companyNit,
                    'before' => $before,
                    'after' => $validated['permissions'],
                ],
                $request
            );
        });

        // Los permisos del rol cambiaron → invalidar la matriz cacheada de
        // todos los usuarios de la empresa (no solo el target: el rol es compartido).
        FeaturePermissionService::forgetCompanyCache($companyNit);

        return new JsonResource($targetMembership->fresh(['user', 'role.permissions']));
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $this->authorizeManagerRole($request);

        $companyNit = $request->attributes->get('active_company_nit');
        $currentUserId = $request->attributes->get('jwt_payload')['sub'] ?? null;

        if ((string) $id === (string) $currentUserId) {
            abort(403, 'No puedes cambiar tu propio estado de acceso.');
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $membership = CompanyUser::where('company_nit', $companyNit)
            ->where('user_id', $id)
            ->with('user')
            ->firstOrFail();

        DB::transaction(function () use ($membership, $validated, $request, $companyNit) {
            $previousStatus = $membership->status;
            $membership->status = $validated['status'];
            $membership->save();

            if ($validated['status'] === 'inactive') {
                $this->jwtService->invalidateUserActiveSession($membership->user_id);
            }

            $this->auditService->log(
                'user.status_changed',
                $membership->user,
                null,
                [
                    'company_nit' => $companyNit,
                    'from' => $previousStatus,
                    'to' => $validated['status'],
                ],
                $request
            );
        });

        return response()->json([
            'user_id' => $membership->user_id,
            'status' => $membership->status,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeManagerRole($request);

        $companyNit = $request->attributes->get('active_company_nit');
        $currentUserId = $request->attributes->get('jwt_payload')['sub'] ?? null;

        $membership = CompanyUser::where('company_nit', $companyNit)
            ->where('user_id', $id)
            ->with(['user', 'role'])
            ->firstOrFail();

        if ((string) $membership->user_id === (string) $currentUserId) {
            abort(403, 'No puedes desvincularte a ti mismo de la empresa.');
        }

        $role = $membership->role;

        if ($role->is_system) {
            $remaining = CompanyUser::where('company_nit', $companyNit)
                ->where('company_role_id', $role->id)
                ->count();

            if ($remaining <= 1) {
                abort(403, 'No puedes desvincular al único '.$role->name.' de la empresa.');
            }
        }

        DB::transaction(function () use ($membership, $role, $request) {
            $user = $membership->user;
            $companyNit = $membership->company_nit;
            $membership->delete();

            $this->auditService->log(
                'user.removed',
                $user,
                null,
                [
                    'company_nit' => $companyNit,
                    'removed_role' => $role->name,
                ],
                $request
            );
        });

        return response()->json(['removed' => true]);
    }

    /**
     * Verifica que el actor tenga al menos una membresía válida en la empresa activa.
     * La autorización fina (permiso CRUD) la realiza el middleware `permission:users.update,*` en routes/api.php;
     * este guard sólo confirma que la membresía existe. La validación adicional contra escalación de privilegios
     * en updatePermissions vive dentro del propio método.
     */
    private function authorizeManagerRole(Request $request): void
    {
        $role = $request->attributes->get('jwt_payload')['role'] ?? null;
        if (! is_array($role) || ! isset($role['id'])) {
            abort(403, 'No autorizado.');
        }
    }
}

<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Feature;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Verifica permisos RBAC de feature+acción para el usuario autenticado por JWT.
 *
 * Reglas:
 *  - Roles con is_system=true (owner) tienen acceso total y omiten verificación.
 *  - Para el resto, la matriz CRUD se consulta desde BD (no del JWT payload)
 *    para detectar cambios recientes de permisos.
 *  - **Caché** (#opt): la matriz se cachea por (company_nit, user_id) con
 *    `Cache::flexible([60, 600])` — sirve resultado stale durante el
 *    refresh background. El observer `CompanyRolePermissionObserver`
 *    invalida la clave al actualizar permisos.
 *
 * Sin caché, el dashboard pegaba a la BD por cada feature consultado en
 * `HandleInertiaRequests::share()` (1 query × N permisos × cada request).
 */
class FeaturePermissionService
{
    private const CACHE_PREFIX = 'feat_perm.matrix';

    /**
     * TTL para el caché de la matriz de permisos. La caché es `flexible`,
     * por lo que sirve stale data después de [fresh_ttl] y refresca en
     * background; al cabo de [stale_ttl] el dato queda inválido.
     *
     * @return array{int, int}
     */
    private static function cacheTtl(): array
    {
        return [60, 600]; // fresh: 60s, stale: 600s
    }

    public static function cacheKey(string $companyNit, int|string $userId): string
    {
        return self::CACHE_PREFIX.':'.$companyNit.':'.$userId;
    }

    /**
     * Invalida la caché de un usuario específico (al actualizar su rol).
     * Llamado desde CompanyRolePermissionObserver y otros puntos de mutación.
     */
    public static function forgetUserCache(string $companyNit, int|string $userId): void
    {
        Cache::forget(self::cacheKey($companyNit, $userId));
    }

    /**
     * Invalida toda la caché de permisos de una empresa (al eliminar o
     * modificar un rol a gran escala). Usa Cache::flush() tagless: en
     * vez de tags (no todos los stores los soportan), forgetByPattern
     * implementaríamos con scan — por simplicidad, se itera por miembros.
     */
    public static function forgetCompanyCache(string $companyNit): void
    {
        CompanyUser::where('company_nit', $companyNit)
            ->pluck('user_id')
            ->each(fn ($uid) => Cache::forget(self::cacheKey($companyNit, (string) $uid)));
    }

    public function hasPermission(Request $request, string $featureGroup, string $action): bool
    {
        $jwtPayload = $request->attributes->get('jwt_payload');
        $companyNit = (string) $request->attributes->get('active_company_nit');
        $userId = $jwtPayload['sub'] ?? null;
        $jwtRole = $jwtPayload['role'] ?? null;

        if (is_array($jwtRole) && ($jwtRole['is_system'] ?? false)) {
            return true;
        }

        if ($userId === null || $companyNit === '') {
            return false;
        }

        $matrix = $this->loadMatrix($companyNit, $userId);
        $slug = "{$featureGroup}.{$action}";

        return ($matrix[$slug] ?? null) === true;
    }

    public function assertPermission(Request $request, string $featureGroup, string $action): void
    {
        if (! $this->hasPermission($request, $featureGroup, $action)) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }
    }

    /**
     * Resuelve el rol activo y la lista de permisos (feature slugs con `can_read`)
     * de un usuario en una empresa, leyendo EN VIVO de la BD.
     *
     * Fuente de verdad ÚNICA para dos consumidores que deben coincidir:
     *  1. `JwtService::issue` — hornea estos datos en el token al hacer login.
     *  2. `BootstrapService::buildSessionContext` — alimenta el sidebar en cada
     *     carga.
     *
     * Antes el bootstrap copiaba `role`/`permissions` del payload del JWT
     * (snapshot rancio): si se editaba un rol a mitad de sesión, el sidebar
     * seguía mostrando los permisos viejos aunque el backend ya validara en vivo
     * y devolviera 403. Centralizar la resolución acá y consultarla en cada
     * bootstrap mantiene el frontend alineado con lo que el backend realmente
     * permite, sin esperar a re-loguear ni a que expire el token.
     *
     * Incluye la regla derivada en runtime de `metrics.view_all_branches`:
     * cobertura total de sedes activas otorga el permiso sin asignación explícita.
     *
     * @return array{role: array{id: string, name: string, is_system: bool}|null, permissions: array<int, string>}
     */
    public function resolveRoleAndPermissions(User $user, ?string $companyNit): array
    {
        if ($companyNit === null) {
            return ['role' => null, 'permissions' => []];
        }

        $membership = $user->companyMemberships()
            ->where('company_nit', $companyNit)
            ->with(['role', 'role.permissions.feature'])
            ->first();

        $roleModel = $membership?->role;
        if ($roleModel === null) {
            return ['role' => null, 'permissions' => []];
        }

        $role = [
            'id' => $roleModel->id,
            'name' => $roleModel->name,
            'is_system' => (bool) $roleModel->is_system,
        ];

        if ($roleModel->is_system) {
            return ['role' => $role, 'permissions' => Feature::pluck('slug')->all()];
        }

        $permissions = $roleModel->permissions
            ->filter(fn ($perm) => $perm->can_read && $perm->feature !== null)
            ->map(fn ($perm) => $perm->feature->slug)
            ->values()
            ->all();

        if (! in_array('metrics.view_all_branches', $permissions, true)) {
            $activeBranchIds = Branch::query()
                ->where('company_nit', $companyNit)
                ->whereNull('archived_at')
                ->pluck('id');

            if ($activeBranchIds->isNotEmpty()) {
                $accessibleIds = $user->accessibleBranches($companyNit)
                    ->pluck('branches.id')
                    ->all();

                $hasFullCoverage = $activeBranchIds->every(
                    fn ($id) => in_array($id, $accessibleIds, true),
                );

                if ($hasFullCoverage) {
                    $permissions[] = 'metrics.view_all_branches';
                }
            }
        }

        return ['role' => $role, 'permissions' => $permissions];
    }

    /**
     * Determina si un usuario puede ver reportes consolidados de empresa.
     *
     * Tres rutas posibles:
     *  1. **Owner** (`role.is_system=true`): bypass automático.
     *  2. **Cobertura total de sedes**: si el usuario tiene acceso a TODAS
     *     las sedes activas (no archivadas) de la empresa vía `branch_users`,
     *     recibe el permiso automáticamente. Si el owner luego crea una
     *     sede nueva y no agrega al usuario, pierde el privilegio hasta
     *     que se le otorgue acceso a la nueva sede.
     *  3. **Permiso explícito**: el rol tiene `metrics.view_all_branches`
     *     asignado vía la matriz de permissions estándar.
     *
     * La evaluación es en runtime (no se persiste). El frontend la
     * consulta para decidir si ofrecer la opción "Todas las sedes" en
     * el `branch-filter-tabs`.
     */
    public function userCanViewConsolidated(User $user, Company $company): bool
    {
        $membership = $user->companyMemberships()
            ->where('company_nit', $company->nit)
            ->with('role.permissions.feature:id,slug')
            ->first();

        if ($membership === null || $membership->role === null) {
            return false;
        }

        // (1) Owner.
        if ($membership->role->is_system) {
            return true;
        }

        // (3) Permiso explícito asignado al rol.
        $hasExplicit = $membership->role->permissions
            ->contains(function ($perm) {
                return $perm->feature?->slug === 'metrics.view_all_branches'
                    && (bool) $perm->can_read === true;
            });
        if ($hasExplicit) {
            return true;
        }

        // (2) Cobertura total: acceso a TODAS las sedes activas.
        $activeBranches = Branch::query()
            ->where('company_nit', $company->nit)
            ->whereNull('archived_at')
            ->pluck('id');

        if ($activeBranches->isEmpty()) {
            return false;
        }

        $accessibleBranches = $user->accessibleBranches($company->nit)->pluck('branches.id')->all();

        return $activeBranches->every(fn ($id) => in_array($id, $accessibleBranches, true));
    }

    /**
     * Carga la matriz `slug → bool` para (empresa, usuario) — caché flexible.
     *
     * El formato compacto reduce la huella en cache vs guardar el modelo entero
     * con sus relaciones. Una matriz típica tiene <80 entradas (features × acciones).
     *
     * @return array<string, bool>
     */
    private function loadMatrix(string $companyNit, int|string $userId): array
    {
        return Cache::flexible(
            self::cacheKey($companyNit, $userId),
            self::cacheTtl(),
            function () use ($companyNit, $userId): array {
                $membership = CompanyUser::where('company_nit', $companyNit)
                    ->where('user_id', $userId)
                    ->with('role.permissions.feature:id,slug')
                    ->first();

                if (! $membership || ! $membership->role) {
                    return [];
                }

                $matrix = [];
                foreach ($membership->role->permissions as $permission) {
                    $featureSlug = $permission->feature?->slug;
                    if ($featureSlug === null) {
                        continue;
                    }

                    // La clave es el slug completo del feature. El gate depende
                    // del sufijo: si es una acción CRUD, usa su columna can_*;
                    // para slugs especiales (deliveries.self_assign,
                    // cash_register.operate_others, employees.view_salary, …)
                    // el grant canónico es can_read — igual que las rutas
                    // (`permission:<slug>,read`) y resolveRoleAndPermissions.
                    // Antes se buscaba una columna inexistente (can_self_assign)
                    // y estos permisos quedaban SIEMPRE denegados para roles
                    // no-system aunque estuvieran asignados.
                    $dotPos = strrpos($featureSlug, '.');
                    $suffix = $dotPos !== false ? substr($featureSlug, $dotPos + 1) : null;
                    $column = in_array($suffix, ['read', 'create', 'update', 'delete'], true)
                        ? "can_{$suffix}"
                        : 'can_read';

                    $matrix[$featureSlug] = (bool) $permission->{$column};
                }

                return $matrix;
            },
        );
    }
}

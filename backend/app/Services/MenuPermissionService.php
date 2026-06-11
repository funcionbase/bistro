<?php

namespace App\Services;

use App\Models\CompanyUser;
use Illuminate\Http\Request;

/**
 * Verifica permisos RBAC específicos de la feature 'menu' para el usuario autenticado.
 *
 * Variante especializada de FeaturePermissionService scoped a menu.{action}.
 * Los roles is_system=true omiten la verificación y tienen acceso completo al menú.
 * assertMenuPermission() hace abort(403) si el permiso no está habilitado.
 */
class MenuPermissionService
{
    public function hasMenuPermission(Request $request, string $action): bool
    {
        $jwtPayload = $request->attributes->get('jwt_payload');
        $companyNit = $request->attributes->get('active_company_nit');
        $userId = $jwtPayload['sub'] ?? null;
        $jwtRole = $jwtPayload['role'] ?? null;

        if (is_array($jwtRole) && ($jwtRole['is_system'] ?? false)) {
            return true;
        }

        $membership = CompanyUser::where('company_nit', $companyNit)
            ->where('user_id', $userId)
            ->with('role.permissions.feature')
            ->first();

        if (! $membership || ! $membership->role) {
            return false;
        }

        $slug = "menu.{$action}";
        $canColumn = "can_{$action}";

        return $membership->role->permissions
            ->contains(fn ($permission) => $permission->feature?->slug === $slug && $permission->{$canColumn} === true);
    }

    public function assertMenuPermission(Request $request, string $action): void
    {
        if (! $this->hasMenuPermission($request, $action)) {
            abort(403, 'No tienes permiso para realizar esta acción en el menú.');
        }
    }
}

<?php

namespace App\Services;

use App\Models\CompanyUser;
use Illuminate\Http\Request;

/**
 * Verifica permisos RBAC específicos de la feature 'reports' para el usuario autenticado.
 *
 * Variante especializada de FeaturePermissionService scoped a reports.{action}.
 * Los roles is_system=true omiten la verificación y tienen acceso completo a reportes y exportaciones.
 * assertPermission() hace abort(403) si el permiso no está habilitado.
 */
class ReportsPermissionService
{
    public function hasPermission(Request $request, string $action): bool
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

        $slug = "reports.{$action}";
        $canColumn = "can_{$action}";

        return $membership->role->permissions
            ->contains(fn ($permission) => $permission->feature?->slug === $slug && $permission->{$canColumn} === true);
    }

    public function assertPermission(Request $request, string $action): void
    {
        if (! $this->hasPermission($request, $action)) {
            abort(403, 'No tienes permiso para ver reportes y métricas.');
        }
    }
}

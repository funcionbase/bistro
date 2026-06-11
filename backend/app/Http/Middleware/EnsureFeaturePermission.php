<?php

namespace App\Http\Middleware;

use App\Models\CompanyRolePermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica permisos RBAC por feature y acción (read/create/update/delete).
 *
 * Se usa como middleware parametrizado: permission:feature,action (e.g. permission:menu,read).
 * Lee: company_role_id y company_role_is_system (inyectados por EnsureCompanyAccess).
 * Los roles con is_system=true omiten la verificación y tienen acceso completo a todas las features.
 * Retorna 500 si la acción no es válida; 403 si el rol no tiene el permiso requerido.
 */
class EnsureFeaturePermission
{
    private const VALID_ACTIONS = ['read', 'create', 'update', 'delete'];

    public function handle(Request $request, Closure $next, string $featureSlug, string $action = 'read'): Response
    {
        if (! in_array($action, self::VALID_ACTIONS, true)) {
            return response()->json(['message' => 'Acción de permiso inválida.'], 500);
        }

        // Roles de sistema tienen acceso completo a todas las funcionalidades.
        if ($request->attributes->get('company_role_is_system', false)) {
            return $next($request);
        }

        $roleId = $request->attributes->get('company_role_id');

        if ($roleId === null) {
            return response()->json(['message' => 'No tienes una empresa activa seleccionada.'], 403);
        }

        $canField = 'can_'.$action;

        $hasPermission = CompanyRolePermission::where('company_role_id', $roleId)
            ->whereHas('feature', fn ($q) => $q->where('slug', $featureSlug))
            ->where($canField, true)
            ->exists();

        if (! $hasPermission) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        return $next($request);
    }
}

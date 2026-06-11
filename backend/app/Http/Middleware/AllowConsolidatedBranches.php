<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Multi-sede (#117): permite a usuarios con `metrics.view_all_branches` consultar
 * reportes consolidados (`?branch=all`) o de una sede específica distinta a su
 * sede activa (`?branch=<uuid>`).
 *
 * Debe ejecutarse DESPUÉS de `branch.access` — depende de que la sede activa ya
 * haya sido validada y de la lista de permisos del JWT.
 *
 * Modos:
 *  - `?branch=all`:
 *      - Requiere permiso `metrics.view_all_branches`.
 *      - Setea `active_branch_id = null` en request attributes → BranchScope no filtra.
 *      - El controlador puede leer `request->attributes->get('consolidated_branches') === true`.
 *  - `?branch=<uuid>`:
 *      - Si coincide con la sede activa: no-op.
 *      - Si distinta y el usuario tiene `metrics.view_all_branches` y la sede pertenece a la
 *        empresa activa: actualiza `active_branch_id` al uuid solicitado para esta request.
 *      - En otros casos: 403.
 *  - Sin `?branch`: no-op (la sede activa del JWT manda).
 */
class AllowConsolidatedBranches
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $param = $request->query('branch');

        if ($param === null || $param === '') {
            return $next($request);
        }

        $payload = $request->attributes->get('jwt_payload') ?? [];
        $permissions = $payload['permissions'] ?? [];
        $activeCompanyNit = $request->attributes->get('active_company_nit')
            ?? ($payload['active_company_nit'] ?? null);
        $hasConsolidatedPermission = in_array('metrics.view_all_branches', $permissions, true)
            || ($payload['role']['is_system'] ?? false) === true;

        if ($param === 'all') {
            if (! $hasConsolidatedPermission) {
                return response()->json([
                    'message' => 'No tienes permiso para consultar reportes consolidados.',
                    'code' => 'CONSOLIDATED_FORBIDDEN',
                ], 403);
            }

            // BranchScope ve null y no aplica filtro. El controlador queda libre de
            // agrupar por branch_id en SQL si quiere desglose.
            $request->attributes->set('active_branch_id', null);
            $request->attributes->set('consolidated_branches', true);

            return $next($request);
        }

        // Sede específica solicitada por uuid.
        $currentBranchId = $request->attributes->get('active_branch_id');
        if ($param === $currentBranchId) {
            return $next($request);
        }

        if (! $hasConsolidatedPermission) {
            return response()->json([
                'message' => 'Solo puedes consultar tu sede activa. Solicita el permiso para ver otras sedes.',
                'code' => 'BRANCH_FILTER_FORBIDDEN',
            ], 403);
        }

        $branch = Branch::query()
            ->where('id', $param)
            ->where('company_nit', $activeCompanyNit)
            ->whereNull('archived_at')
            ->first();

        if ($branch === null) {
            return response()->json([
                'message' => 'Sede no encontrada en la empresa activa.',
                'code' => 'BRANCH_NOT_FOUND',
            ], 404);
        }

        $request->attributes->set('active_branch_id', $branch->id);
        $request->attributes->set('active_branch', $branch);

        return $next($request);
    }
}

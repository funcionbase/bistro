<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Resuelve el contexto activo (empresa, sede, payload JWT) desde el Request.
 *
 * Los middlewares `ValidateJwt`, `EnsureCompanyAccess` y `EnsureBranchAccess`
 * inyectan `active_company_nit`, `active_branch_id` y `jwt_payload` en
 * `$request->attributes`. Este trait centraliza el acceso para que los
 * controllers no repitan `(string) $request->attributes->get(...)` en cada
 * método.
 *
 * Si el middleware faltara, los métodos devuelven string vacío / null en lugar
 * de lanzar — el caller decide si tratar la ausencia como error.
 */
trait ResolvesActiveContext
{
    protected function activeCompanyNit(Request $request): string
    {
        return (string) $request->attributes->get('active_company_nit');
    }

    protected function activeBranchId(Request $request): string
    {
        return (string) $request->attributes->get('active_branch_id');
    }

    /** @return array<string, mixed>|null */
    protected function jwtPayload(Request $request): ?array
    {
        $payload = $request->attributes->get('jwt_payload');

        return is_array($payload) ? $payload : null;
    }

    /**
     * Devuelve true cuando el request opera en modo consolidado
     * (`?branch=all` con permiso `metrics.view_all_branches`).
     */
    protected function isConsolidatedBranches(Request $request): bool
    {
        return (bool) $request->attributes->get('consolidated_branches', false);
    }
}

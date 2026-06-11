<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate de verificación de propiedad de la empresa.
 *
 * Aplica DESPUÉS de `EnsureCompanyAccess`. Lee el `active_company_nit` ya
 * resuelto por ese middleware y bloquea cualquier operación si el estado de
 * la empresa no está en `config('companies.verified')`.
 *
 * Respuesta:
 *  - 403 JSON con `{ message, code: 'company_not_verified', status: <status> }`.
 *  - El frontend usa `code` para enrutar a `/company/under-review` sin
 *    necesidad de un round-trip adicional.
 *
 * El cambio de estado (`pending_activation → verified | rejected`) se ejecuta
 * desde el workflow `.github/workflows/company-status.yml`, no desde la app.
 */
class EnsureCompanyVerified
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $activeCompanyNit = $request->attributes->get('active_company_nit');

        if ($activeCompanyNit === null) {
            return response()->json(['message' => 'No has seleccionado una empresa activa.'], 403);
        }

        $company = Company::query()->where('nit', $activeCompanyNit)->first();

        if ($company === null) {
            return response()->json([
                'message' => 'Empresa no encontrada.',
                'code' => 'company_not_found',
            ], 403);
        }

        $verifiedStatuses = (array) config('companies.verified', ['active', 'past_due']);

        if (! in_array($company->status, $verifiedStatuses, true)) {
            return response()->json([
                'message' => 'Tu empresa está pendiente de verificación.',
                'code' => 'company_not_verified',
                'status' => $company->status,
            ], 403);
        }

        return $next($request);
    }
}

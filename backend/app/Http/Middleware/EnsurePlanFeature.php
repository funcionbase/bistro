<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\BillingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate por feature del plan de facturación activo de la empresa (ej. `dian`,
 * exclusiva del Plan Plus). Se usa después de `company.access` (que inyecta
 * `active_company_nit`).
 *
 * Uso en rutas:
 *   Route::middleware(['jwt','company.access','plan.feature:dian'])
 *     ->get('/dian/...', ...);
 *
 * Si el plan activo no incluye la feature, responde 403
 * `plan.feature_not_included` — el frontend usa este código para mostrar el
 * bloqueo informativo en vez de un error genérico.
 */
class EnsurePlanFeature
{
    public function __construct(private readonly BillingService $billing) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $companyNit = $request->attributes->get('active_company_nit');

        if ($companyNit === null) {
            return response()->json([
                'message' => 'No hay empresa activa para evaluar el plan.',
                'code' => 'NO_ACTIVE_COMPANY',
            ], 422);
        }

        if (! $this->billing->companyHasFeature((string) $companyNit, $feature)) {
            return response()->json([
                'message' => 'Esta opción no está incluida en tu plan actual.',
                'code' => 'plan.feature_not_included',
                'feature' => $feature,
            ], 403);
        }

        return $next($request);
    }
}

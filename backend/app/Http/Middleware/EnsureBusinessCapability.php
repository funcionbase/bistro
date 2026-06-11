<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Services\BusinessCapabilityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate por capability del vertical de la sede activa. Se usa después de
 * `branch.access` (que inyecta `active_branch`).
 *
 * Uso en rutas:
 *   Route::middleware(['jwt','company.access','branch.access','business.capability:kds'])
 *     ->get('/kds/...', ...);
 *
 * Si la capability no aplica al vertical de la sede (ni hay override que la
 * habilite) responde 403 BUSINESS_CAPABILITY_DENIED.
 */
class EnsureBusinessCapability
{
    public function __construct(private readonly BusinessCapabilityService $capabilities) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        $branch = $request->attributes->get('active_branch');

        if (! $branch instanceof Branch) {
            return response()->json([
                'message' => 'No hay sede activa para evaluar la capability.',
                'code' => 'NO_ACTIVE_BRANCH',
            ], 422);
        }

        if (! $this->capabilities->userCan($branch, $flag)) {
            return response()->json([
                'message' => 'Esta funcionalidad no aplica al tipo de negocio de la sede.',
                'code' => 'BUSINESS_CAPABILITY_DENIED',
                'capability' => $flag,
                'business_type' => $branch->business_type_id,
            ], 403);
        }

        return $next($request);
    }
}

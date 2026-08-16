<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\KdsStation;
use App\Services\KdsDeviceTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación por device-token para endpoints KDS.
 *
 * Sustituye a `jwt + company.access + branch.access` en las rutas
 * `/api/v1/kds/{stationSlug}/*` consumidas por las tabletas físicas de
 * cocina. La sesión web completa no aplica acá — la tableta no inicia
 * sesión con email/clave, solo presenta su token.
 *
 * El servicio resuelve el token (header Authorization, cookie o query),
 * valida que no esté revocado, y actualiza last_seen_at + last_ip. Luego
 * este middleware:
 *  - Valida el stationSlug de la URL contra la estación del token (no se
 *    permite usar un token de una estación contra otra).
 *  - Valida que la empresa no esté completamente bloqueada (mismo guard
 *    que EnsureCompanyNotBlocked aplica para sesiones web).
 *  - Inyecta en `$request->attributes`:
 *    - `active_company_nit`
 *    - `active_branch_id`
 *    - `active_station_id`
 *    - `active_station_slug`
 *    - `kds_device_token_id`
 *
 * Las rutas se montan con throttle `kds-device` (60/min per token,
 * definido en AppServiceProvider).
 */
class EnsureKdsDeviceToken
{
    public function __construct(private readonly KdsDeviceTokenService $tokens) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokens->resolveFromRequest($request);

        if ($token === null) {
            return $this->error($request, 'Token de dispositivo KDS inválido o revocado.', 401);
        }

        $routeStationSlug = (string) $request->route('stationSlug');

        $station = KdsStation::query()
            ->where('id', $token->station_id)
            ->whereNull('archived_at')
            ->first();

        if ($station === null) {
            return $this->error($request, 'La estación asociada al token ya no está activa.', 410);
        }

        if ($station->slug !== $routeStationSlug) {
            return $this->error(
                $request,
                'El token pertenece a otra estación distinta a la solicitada.',
                403,
            );
        }

        $company = Company::query()->where('nit', $token->company_nit)->first();
        if ($company === null || $this->isCompanyBlocked($company)) {
            return $this->error($request, 'La empresa no está disponible.', 403);
        }

        $request->attributes->set('active_company_nit', $token->company_nit);
        $request->attributes->set('active_branch_id', $token->branch_id);
        $request->attributes->set('active_station_id', $station->id);
        $request->attributes->set('active_station_slug', $station->slug);
        $request->attributes->set('kds_device_token_id', $token->id);

        return $next($request);
    }

    /**
     * Mismo umbral de bloqueo que aplica al sidebar y EnsureCompanyNotBlocked:
     * la empresa con `suspended` y similares no permite operación KDS.
     */
    private function isCompanyBlocked(Company $company): bool
    {
        $blocking = (array) config('companies.fully_blocked', ['suspended']);

        return in_array((string) $company->status, $blocking, true);
    }

    /**
     * Respuesta uniforme: JSON para API/Ajax, HTML plano para web (las
     * tabletas montan la URL en kiosk-mode; un 401 web debe ser legible
     * sin abrir devtools).
     */
    private function error(Request $request, string $message, int $status): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], $status);
        }

        return response($message, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}

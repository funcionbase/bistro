<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\TableSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hidrata `$request->attributes->get('table_guest')` y `'table_session'` a
 * partir de la cookie `tdt_*` que ataja al comensal.
 *
 * Aplica a rutas `/t/{qr_token}/*` distintas del join. Sin cookie o cookie
 * inválida → atributos quedan en null y el controller decide redirigir.
 *
 * Si la empresa del QR está bloqueada por mora, aborta 404 antes de
 * hidratar el guest. El comensal ve "no encontrado" sin distinguir entre
 * QR inválido y restaurante en mora — no se filtra información comercial.
 */
class ResolveTableGuest
{
    public function __construct(private readonly TableSessionService $tableSessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $qrToken = $request->route('qr_token');

        if (is_string($qrToken) && $qrToken !== '') {
            $table = $this->tableSessions->resolveTable($qrToken);

            // Guard de empresa operativa. Indistinguible de "QR inválido".
            $company = Company::query()->where('nit', $table->company_nit)->first();
            if ($company === null || ! $company->canServePublic()) {
                abort(404);
            }

            $cookieName = 'tdt_'.substr(hash('sha256', $qrToken), 0, 16);
            $deviceToken = $request->cookie($cookieName);

            if (is_string($deviceToken) && $deviceToken !== '') {
                $guest = $this->tableSessions->resolveGuestByDeviceToken($table, $deviceToken);

                if ($guest !== null) {
                    $request->attributes->set('table_guest', $guest);
                    $request->attributes->set('table_session', $guest->session);
                    $request->attributes->set('table_id', $table->id);
                }
            }
        }

        return $next($request);
    }
}

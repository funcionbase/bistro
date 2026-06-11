<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\KdsStation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla standalone full-screen del KDS por estación.
 *
 * El middleware `kds.device` ya validó el token y la coincidencia con
 * `stationSlug`, e inyectó `active_station_id`, `active_branch_id`,
 * `active_company_nit` en `$request->attributes`. Este controller solo
 * resuelve los datos para Inertia y, si el token llegó por query, persiste
 * cookie HttpOnly para que el dispositivo no tenga que volver a presentarlo.
 *
 * El layout `kds-standalone-layout` se monta del lado React (sin sidebar,
 * sin header de app, `min-h-dvh`). Esta página no comparte props con
 * `HandleInertiaRequests` porque es kiosk: no hay usuario logueado.
 */
class KdsStandaloneController extends Controller
{
    public function show(Request $request, string $stationSlug): Response|RedirectResponse
    {
        $stationId = (string) $request->attributes->get('active_station_id');
        $station = KdsStation::query()->whereKey($stationId)->firstOrFail();

        // Si el token vino por query string, lo persistimos como cookie
        // HttpOnly y limpiamos la URL para no exponer el token en el
        // historial del browser ni en logs de proxy. El token ya está en
        // BD hasheado, pero el claro en URL puede pegársele a referer.
        $queryDevice = $request->query('device');
        if (is_string($queryDevice) && $queryDevice !== '') {
            return redirect()
                ->route('kds.station', ['stationSlug' => $stationSlug])
                ->withCookie(cookie(
                    name: 'kds_device_token',
                    value: $queryDevice,
                    minutes: 60 * 24 * 365, // 1 año
                    path: '/',
                    domain: null,
                    secure: $request->isSecure(),
                    httpOnly: true,
                    raw: false,
                    sameSite: 'lax',
                ));
        }

        return Inertia::render('kds/station', [
            'stationSlug' => $stationSlug,
            'station' => [
                'id' => $station->id,
                'slug' => $station->slug,
                'name' => $station->name,
                'color' => $station->color,
                'sla_warn_minutes' => $station->sla_warn_minutes,
                'sla_alert_minutes' => $station->sla_alert_minutes,
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Redirige al frontend SPA (proyecto independiente, #220).
 *
 * El backend ya no sirve frontend. Cualquier ruta de página que llegue
 * al backend (enlaces viejos, accesos directos, el callback OAuth) se
 * reenvía al frontend conservando path y query string.
 */
class FrontendRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return redirect()->away($frontend.$request->getRequestUri());
    }
}

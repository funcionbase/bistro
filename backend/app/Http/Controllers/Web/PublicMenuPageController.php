<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Renderiza la cáscara de la página pública del menú. Soporta dos URLs:
 *  - /menus/{nit}: destino directo del QR. El controller verifica que el restaurante
 *    exista (404 fast) y redirige (302) a /menus/ con el NIT en session flash. Al
 *    rebote, /menus/ recibe el NIT como prop y lo persiste en localStorage.
 *    Hacemos el cambio server-side para evitar la pelea con Inertia v2 — su listener
 *    de scroll re-aplica replaceState con su URL interna y deshace cualquier
 *    history.replaceState que hagamos desde el cliente.
 *  - /menus: pasa el NIT desde session flash si vino del redirect; si no, null →
 *    el cliente resuelve desde localStorage. Si tampoco hay nada, empty state.
 *
 * No requiere autenticación. Las validaciones de horario, caja y menú activo + el
 * branding (logo, color, nombre) viven en MenuController::showPublic — el cliente
 * las consume vía /api/v1/public/menu/{nit}.
 *
 * El query param ?table=N también viaja por session flash → preselección de mesa.
 */
class PublicMenuPageController extends Controller
{
    public function __invoke(Request $request, ?string $nit = null): InertiaResponse|RedirectResponse
    {
        if ($nit !== null) {
            // 404 fast si el QR apunta a un NIT que no existe.
            Company::query()->where('nit', $nit)->firstOrFail();

            $tableParam = $request->query('table');
            $table = is_string($tableParam) && $tableParam !== '' ? mb_substr($tableParam, 0, 16) : null;

            return redirect()
                ->route('public.menu.alias')
                ->with([
                    '_public_menu_nit' => $nit,
                    '_public_menu_table' => $table,
                ]);
        }

        $sessionNit = $request->session()->get('_public_menu_nit');
        $sessionTable = $request->session()->get('_public_menu_table');

        return Inertia::render('menu/public', [
            'nit' => is_string($sessionNit) ? $sessionNit : null,
            'table' => is_string($sessionTable) ? $sessionTable : null,
        ]);
    }
}

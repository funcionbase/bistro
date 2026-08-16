<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\TableMenuContextResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\TableSessionGuest;
use App\Services\TableSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contexto del menú del comensal dentro del flujo público de mesa.
 *
 * API REST — migrado desde `Web\TableMenuController` (SPA). Antes hacía
 * `Inertia::render('table/menu')`; ahora devuelve JSON que la SPA usa para
 * hidratar `pages/table/menu.tsx`.
 *
 * Requiere cookie `tdt_*` válida (resuelta por el middleware `table.guest` en
 * `$request->attributes`). Sin cookie → 401, y la SPA redirige al join.
 *
 * El catálogo en sí (items, precios) lo carga el frontend desde la API
 * pública existente (`/api/v1/public/menu/{nit}`); este endpoint solo aporta
 * la identidad del comensal y los datos de la sede resueltos por el QR.
 */
class TableMenuController extends Controller
{
    public function __construct(private readonly TableSessionService $tableSessions) {}

    public function __invoke(Request $request, string $qrToken): JsonResponse
    {
        $table = $this->tableSessions->resolveTable($qrToken);
        $company = Company::query()->where('nit', $table->company_nit)->firstOrFail();

        // Empresa bloqueada por mora — el comensal recibe 404
        // indistinguible de un QR inválido. No revela motivo comercial.
        if (! $company->canServePublic()) {
            abort(404);
        }

        /** @var TableSessionGuest|null $guest */
        $guest = $request->attributes->get('table_guest');

        if ($guest === null) {
            // Sin cookie de comensal: la SPA debe enviar al formulario de unión.
            return response()->json([
                'message' => 'No estás en la mesa. Volvé a escanear el QR.',
            ], 401);
        }

        $branch = Branch::query()->whereKey($table->branch_id)->firstOrFail();

        return (new TableMenuContextResource($guest, $table, $branch, $company, $qrToken))
            ->response();
    }
}

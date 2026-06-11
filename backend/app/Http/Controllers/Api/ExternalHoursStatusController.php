<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\BusinessHoursService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint exclusivo para bots externos que consultan el estado de apertura del restaurante.
 *
 * Solo acepta JWT de bot (middleware bot.jwt); no requiere company.access.
 * El company_nit se obtiene del payload del JWT de bot (bot_company_nit inyectado por ValidateBotJwt).
 * Retorna el mismo snapshot que BusinessHoursService::getCurrentStatus() más el company_nit.
 *
 * El bot no especifica sede; horarios/excepciones son por sede, así que se
 * resuelve la sede por defecto de la empresa para dar una respuesta determinista
 * (sin esto se tomaba una sede arbitraria).
 */
class ExternalHoursStatusController extends Controller
{
    public function __construct(private readonly BusinessHoursService $businessHoursService) {}

    public function show(Request $request): JsonResponse
    {
        $companyNit = $request->attributes->get('bot_company_nit');

        $branchId = Branch::resolveDefault($companyNit)?->id;

        $status = $this->businessHoursService->getCurrentStatus($companyNit, null, $branchId);

        return response()->json(['data' => array_merge(['company_nit' => $companyNit], $status)]);
    }
}

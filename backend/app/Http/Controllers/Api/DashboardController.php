<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Web\DashboardController as WebDashboardController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint de dashboard para el shell SPA.
 *
 * Reutiliza la lógica de métricas del DashboardController web (métodos
 * `build*` ahora `protected`). Emite en JSON lo que antes iban como
 * props diferidas de Inertia.
 *
 * El contexto de empresa/sede ya viene resuelto por el stack de middleware
 * (`jwt`, `company.access`, `branch.access`, `metrics.consolidated`).
 */
class DashboardController extends WebDashboardController
{
    public function data(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt_payload');
        $companyNit = $request->attributes->get('active_company_nit');
        $period = $this->resolveRequestedPeriod($request->query('period', 'today'));

        $jwtPayload = is_array($payload) ? $payload : null;
        $needsProfileCompletion = ($jwtPayload['enrollment_step'] ?? 'complete') !== 'complete';

        return response()->json([
            'period' => $period,
            'needsProfileCompletion' => $needsProfileCompletion,
            'summary' => $this->buildSummary($companyNit, $period, $jwtPayload),
            'heatmap' => $this->buildHeatmap($companyNit, $period, $jwtPayload),
            'abandonment' => $this->buildAbandonment($companyNit, $period, $jwtPayload),
            'deliveries' => $this->buildDeliveries($companyNit, $period, $jwtPayload),
            'lowStockInventory' => $this->buildLowStockInventory($companyNit, $jwtPayload),
        ]);
    }
}

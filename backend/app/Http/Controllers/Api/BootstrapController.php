<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint de bootstrap del frontend SPA.
 *
 * Replica las shared props que hoy emite HandleInertiaRequests para que el
 * cliente migrado pueda obtener auth, empresa activa, sedes, permisos y
 * catálogos canónicos en una sola llamada al iniciar sesión / cambiar de
 * empresa / cambiar de sede.
 *
 * Convivencia con Inertia: durante la migración ambos consumen
 * BootstrapService — cero drift entre transportes.
 */
class BootstrapController extends Controller
{
    public function __construct(
        private readonly BootstrapService $bootstrap,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt_payload');
        $userId = is_array($payload) ? ($payload['sub'] ?? null) : null;
        $user = $userId ? User::find($userId) : null;

        $context = $this->bootstrap->buildSessionContext($request);
        $catalogs = $this->bootstrap->buildCatalogs();

        return response()->json([
            'auth' => [
                'user' => $user,
            ],
            'needsProfileCompletion' => $user ? $user->needsProfileCompletion() : false,
            'companies' => $context['companies'],
            'activeCompany' => $context['activeCompany'],
            'branches' => $context['branches'],
            'activeBranch' => $context['activeBranch'],
            'role' => $context['role'],
            'permissions' => $context['permissions'],
            ...$catalogs,
        ]);
    }
}

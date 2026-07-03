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
    /**
     * Versión del backend memoizada por worker PHP. Se lee de composer.json
     * en runtime (no en build del frontend) para que el footer `bv` refleje
     * SIEMPRE el código realmente desplegado, sin cache compartido que pueda
     * sobrevivir a un deploy.
     */
    private static ?string $backendVersion = null;

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
            'versions' => [
                'backend' => self::backendVersion(),
            ],
            ...$catalogs,
        ]);
    }

    private static function backendVersion(): string
    {
        if (self::$backendVersion === null) {
            $manifest = json_decode((string) file_get_contents(base_path('composer.json')), true);
            self::$backendVersion = is_array($manifest) ? (string) ($manifest['version'] ?? '0.0.0') : '0.0.0';
        }

        return self::$backendVersion;
    }
}

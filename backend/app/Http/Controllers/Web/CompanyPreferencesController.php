<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\FeaturePermissionService;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use RuntimeException;

/**
 * Renderiza la página de preferencias de empresa. El gate se delega al mismo servicio RBAC
 * que la API (`permission:company.update,update`), no a una lista hardcodeada de nombres de rol.
 *
 * El JWT se extrae via JwtService::extractTokenFromRequest (cookie HttpOnly > Authorization > session > query).
 * Si el token es inválido o ausente, redirige al dashboard. Si el rol no tiene permiso `company.update`,
 * redirige al dashboard. El token se reemite si expira en <300s y la cookie HttpOnly se renueva via
 * Cookie::queue(). El frontend recibe un marker `'__authenticated__'` (no el JWT real).
 */
class CompanyPreferencesController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly FeaturePermissionService $featurePermission,
    ) {}

    public function index(Request $request): mixed
    {
        $token = $this->jwtService->extractTokenFromRequest($request);
        $activeCompany = null;

        if ($token) {
            try {
                $payload = $this->jwtService->verify($token);
                if (($payload['exp'] ?? 0) - time() <= 300) {
                    $token = $this->jwtService->reissue($payload);
                    Cookie::queue($this->jwtService->buildCookie($token));
                } elseif (! $request->cookie(JwtService::COOKIE_NAME)) {
                    // Migración: token válido vino por Bearer/session/query pero no hay cookie.
                    Cookie::queue($this->jwtService->buildCookie($token));
                }

                $activeCompanyNit = $payload['active_company_nit'] ?? null;
                if (! $activeCompanyNit) {
                    return redirect()->route('dashboard');
                }

                // Mismo gate RBAC que la API. Roles personalizados con `company.update` ahora pasan;
                // antes el controller comparaba `role.name` contra ['Propietario', 'Administrador']
                // y dejaba la UI desalineada con el backend (la API permitía actualizar pero
                // la página redirigía al dashboard).
                $synthetic = Request::create('/');
                $synthetic->attributes->set('active_company_nit', $activeCompanyNit);
                $synthetic->attributes->set('jwt_payload', $payload);

                if (! $this->featurePermission->hasPermission($synthetic, 'company', 'update')) {
                    return redirect()->route('dashboard');
                }

                $activeCompany = collect($payload['companies'] ?? [])
                    ->first(fn (array $c) => $c['nit'] === $activeCompanyNit);
            } catch (RuntimeException) {
                return redirect()->route('dashboard');
            }
        }

        return Inertia::render('company/preferences', [
            'token' => $token ? '__authenticated__' : null,
            'activeCompany' => $activeCompany,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Services\AuditService;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate de bloqueo comercial por mora prolongada.
 *
 * Funciona en dos contextos:
 *  - API: requiere el stack previo (`jwt + company.access + company.verified`)
 *    para que `active_company_nit` esté inyectado en `request->attributes`.
 *    Responde con `403 + JSON` y `code='company_payment_blocked'`.
 *  - Web: se monta en el grupo `web` (`bootstrap/app.php`). Como las rutas web
 *    no comparten el stack de auth de API, el middleware resuelve la empresa
 *    activa leyendo el JWT inline (cookie HttpOnly o header). En caso de
 *    bloqueo, redirige a `/dashboard` con un flash `payment_blocked` para que
 *    el frontend muestre toast/banner.
 *
 * Allow-list separada por contexto. La idea es que un usuario con empresa
 * suspendida pueda: ver su dashboard (con banner), regularizar en /billing,
 * editar datos de empresa, y cerrar sesión — nada más.
 *
 * Auditoría: cada bloqueo registra `company.access_blocked_by_suspension` con
 * throttle 1/min por user+ruta vía cache shared. Sin throttle, un SPA con
 * polling cada 5s inundaría `audit_logs`.
 *
 * El middleware NO bloquea por `pending_activation`/`rejected`/`inactive` —
 * esos los maneja `EnsureCompanyVerified`. Solo gatea `config('companies.fully_blocked')`
 * (hoy: `suspended`).
 */
class EnsureCompanyNotBlocked
{
    /**
     * Rutas API que un usuario suspendido puede consumir.
     *
     * @var list<string>
     */
    private const ALLOWED_API_ROUTE_PATTERNS = [
        'api.billing.*',
        'api.companies.active',
        'api.auth.logout',
        'api.auth.switch-company',
    ];

    /**
     * Rutas web que un usuario suspendido puede ver. Cualquier otra ruta web
     * se redirige a `dashboard` con flash `payment_blocked`. Incluye flujos
     * de auth, settings personales del usuario, billing, mi empresa, PWA y
     * rutas públicas (sin JWT) que igual pasan por el grupo web.
     *
     * @var list<string>
     */
    private const ALLOWED_WEB_ROUTE_PATTERNS = [
        'dashboard',
        'company.settings',
        'company.preferences',
        'company.under-review',
        'billing',
        'logout',
        'login',
        'login.attempt',
        'register',
        'register.attempt',
        'password.*',
        'verification.*',
        'auth.*',
        'home',
        'me',
        'me.*',
        'profile.*',
        'appearance',
        'pwa.*',
        'health.*',
        'storage-proxy',
        'public.*',
        'dev.errors.preview',
    ];

    public function __construct(
        private readonly AuditService $auditService,
        private readonly JwtService $jwtService,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isApi = $this->isApiContext($request);

        $context = $this->resolveCompanyContext($request);
        if ($context === null) {
            return $next($request);
        }

        [$company, $userId] = $context;

        $blockedStatuses = (array) config('companies.fully_blocked', ['suspended']);

        if (! in_array($company->status, $blockedStatuses, true)) {
            return $next($request);
        }

        if ($this->isAllowedRoute($request, $isApi)) {
            return $next($request);
        }

        $this->auditBlockedAccess($request, $company, $userId, $isApi);

        if ($isApi) {
            return response()->json([
                'message' => 'Tu cuenta está bloqueada por mora. Sube un comprobante de pago en la pantalla de facturación.',
                'code' => 'company_payment_blocked',
                'status' => $company->status,
            ], 403);
        }

        return redirect()->route('dashboard')->with('payment_blocked', [
            'status' => $company->status,
            'payment_blocked_at' => $company->payment_blocked_at?->toIso8601String(),
        ]);
    }

    private function isApiContext(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    /**
     * Resuelve la empresa activa + user_id del request.
     *
     * Primero busca el attribute `active_company_nit` que pone `EnsureCompanyAccess`
     * en el stack API. Si no está disponible (caso web), extrae del JWT vía
     * cookie HttpOnly / header / sesión / query, usando el mismo flujo que
     * `HandleInertiaRequests`. Sin token válido devuelve `null` y el middleware
     * deja pasar — esto preserva las rutas públicas (menú, mesa con QR, PWA).
     *
     * @return array{0: Company, 1: int|null}|null
     */
    private function resolveCompanyContext(Request $request): ?array
    {
        $activeCompanyNit = $request->attributes->get('active_company_nit');
        $userId = null;
        $jwtPayload = $request->attributes->get('jwt_payload');

        if (is_array($jwtPayload)) {
            $userId = ($jwtPayload['sub'] ?? null) ? (string) $jwtPayload['sub'] : null;
        }

        if ($activeCompanyNit === null) {
            $token = $this->jwtService->extractTokenFromRequest($request);
            if (! is_string($token) || $token === '') {
                return null;
            }

            try {
                $payload = $this->jwtService->verify($token);
            } catch (RuntimeException) {
                return null;
            }

            $activeCompanyNit = $payload['active_company_nit'] ?? null;
            $userId = ($payload['sub'] ?? null) ? (string) $payload['sub'] : null;

            if ($activeCompanyNit === null) {
                return null;
            }
        }

        $company = Company::query()->where('nit', $activeCompanyNit)->first();

        if ($company === null) {
            return null;
        }

        return [$company, $userId];
    }

    private function isAllowedRoute(Request $request, bool $isApi): bool
    {
        $routeName = $request->route()?->getName();
        if ($routeName === null) {
            return false;
        }

        $patterns = $isApi ? self::ALLOWED_API_ROUTE_PATTERNS : self::ALLOWED_WEB_ROUTE_PATTERNS;

        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registra el intento de acceso bloqueado en `audit_logs` con throttle
     * 1/min por user+ruta. Requiere cache store compartido en PDN
     * (CACHE_STORE=redis/dynamodb) para que el throttle funcione cross-instance.
     */
    private function auditBlockedAccess(Request $request, Company $company, ?string $userId, bool $isApi): void
    {
        $routeName = $request->route()?->getName() ?? $request->path();
        $userKey = $userId !== null ? (string) $userId : 'anon';
        $cacheKey = "audit:blocked:{$userKey}:{$routeName}";

        if (! Cache::add($cacheKey, 1, now()->addMinute())) {
            return;
        }

        $user = $userId !== null ? User::find($userId) : null;

        $this->auditService->log(
            'company.access_blocked_by_suspension',
            $user,
            $company,
            [
                'route' => $routeName,
                'user_id' => $userId,
                'company_nit' => $company->nit,
                'context' => $isApi ? 'api' : 'web',
                'path' => $request->path(),
            ],
            $request,
        );
    }
}

<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserActiveToken;
use App\Support\SignedAssetUrl;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie as CookieFacade;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Emite, verifica y refresca JWTs de usuario con payload cifrado en AES-256-CBC y firmado en HS256.
 *
 * Estructura del payload: sub, email, enrollment_step, active_company_nit, role, permissions, companies, iat, exp, auth_time.
 * El payload va cifrado (no solo firmado) para ocultar datos sensibles del cliente.
 * Auto-refresh: ValidateJwt re-emite el token cuando exp - now() < 300s, pero el
 * refresh nunca extiende la sesión más allá de `auth_time + JWT_MAX_LIFETIME`
 * (tope absoluto). Pasado el tope, verify() rechaza el token y obliga re-login.
 * Esto evita sesiones eternas por polling del SPA (KDS usa device-token, no JWT).
 * Lista negra opcional: si jwt_blacklist_enabled=true, los tokens invalidados se guardan en caché hasta expirar.
 * Registro activo: cada emisión actualiza user_active_tokens para soporte de invalidación por membresía.
 *
 * Almacenamiento en navegador: el JWT viaja en una cookie HttpOnly + Secure + SameSite=Lax
 * (`COOKIE_NAME`). El JS de la app no la puede leer, mitigando el robo por XSS. Para clientes
 * API externos (no-navegador) se sigue aceptando `Authorization: Bearer`.
 *
 * @env JWT_SECRET — clave HMAC-SHA256 para firma
 * @env JWT_PAYLOAD_ENCRYPTION_KEY — clave base para derivar la clave AES-256 (SHA-256 del secreto)
 * @env JWT_TTL — tiempo de vida del token / ventana de refresh deslizante en segundos (default: 21600 = 6h)
 * @env JWT_MAX_LIFETIME — tope de vida absoluto de la sesión en segundos (default: 43200 = 12h)
 */
class JwtService
{
    /**
     * Nombre de la cookie HttpOnly que transporta el JWT en el navegador.
     */
    public const COOKIE_NAME = 'flexyflow_jwt';

    private const ALGORITHM = 'HS256';

    private const CIPHER = 'AES-256-CBC';

    private string $signingKey;

    private string $encryptionKey;

    private int $ttlSeconds;

    private int $maxLifetimeSeconds;

    public function __construct()
    {
        $this->signingKey = (string) config('auth.jwt_secret');
        $this->encryptionKey = (string) config('auth.jwt_payload_encryption_key');
        $this->ttlSeconds = (int) config('auth.jwt_ttl', 21600);
        $this->maxLifetimeSeconds = (int) config('auth.jwt_max_lifetime', 43200);

        if (empty($this->signingKey) || empty($this->encryptionKey)) {
            throw new RuntimeException('JWT_SECRET and JWT_PAYLOAD_ENCRYPTION_KEY must be configured.');
        }
    }

    /**
     * Extrae el JWT de un Request en orden de prioridad:
     *   1. Cookie HttpOnly `flexyflow_jwt` (default para navegador — no accesible a JS)
     *   2. Header `Authorization: Bearer <token>` (clientes API externos / integraciones)
     *   3. Session flash key `jwt_token` (handoff entre redirects internos sin exponer en URL)
     *
     * CIBER-07: se retiró la aceptación por query param `?token=`. Un JWT en la
     * URL se filtra a access logs (nginx/ALB/CloudWatch), historial del navegador,
     * header `Referer` y caches de proxy → secuestro de sesión. El front ya migró
     * a cookie HttpOnly; los redirects que aún arrastran `?token=` son inocuos
     * (el query se ignora para autenticar).
     */
    public function extractTokenFromRequest(Request $request): ?string
    {
        $cookie = $request->cookie(self::COOKIE_NAME);
        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        if ($request->hasSession()) {
            $session = $request->session()->pull('jwt_token');
            if (is_string($session) && $session !== '') {
                return $session;
            }
        }

        return null;
    }

    /**
     * Construye una cookie HttpOnly que transporta el JWT al navegador de forma
     * inaccesible para JS. Su TTL coincide con el del token.
     *
     * `secure` y `same_site` se leen de `config('session.*')` (env-driven). El
     * deploy es cross-origin same-site (SPA y API en hosts distintos bajo
     * flexyflow.co): en PDN debe ir `SESSION_SAME_SITE=none` +
     * `SESSION_SECURE_COOKIE=true` para que el navegador adjunte la cookie en
     * los fetch del SPA. En local sobre HTTP ambos quedan en su default seguro
     * (`lax` / `false`) porque el dev pasa por el proxy de Vite (same-origin).
     *
     * Guard: `SameSite=None` exige `Secure` (los navegadores descartan la
     * cookie si falta). Si `same_site` quedara en `none` con `secure=false`
     * por una mala config, degradamos a `lax` para no romper la sesión.
     */
    public function buildCookie(string $token): Cookie
    {
        $minutes = (int) ceil($this->ttlSeconds / 60);
        $secure = (bool) config('session.secure', ! app()->isLocal());
        $domain = config('session.domain');
        $sameSite = (string) config('session.same_site', 'lax');

        if ($sameSite === 'none' && ! $secure) {
            $sameSite = 'lax';
        }

        return CookieFacade::make(
            name: self::COOKIE_NAME,
            value: $token,
            minutes: $minutes,
            path: '/',
            domain: $domain,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: $sameSite,
        );
    }

    /**
     * Cookie de borrado para invalidar la sesión del navegador.
     */
    public function forgetCookie(): Cookie
    {
        return CookieFacade::forget(self::COOKIE_NAME, '/', config('session.domain'));
    }

    /**
     * Issue a signed, AES-256-encrypted JWT for the given user.
     *
     * @param  Collection<int, Company>|array<int, Company>  $companies
     */
    public function issue(User $user, Collection|array $companies = [], ?string $activeCompanyNit = null, ?string $activeBranchId = null): string
    {
        // Incluir todas las empresas del usuario sin filtrar por estado;
        // el selector las muestra pero bloquea la selección de las no activas.
        if (empty($companies)) {
            $companies = $user->companies()->get();
        } else {
            $companies = collect($companies);
        }

        $memberships = $user->companyUsers()->get()->keyBy('company_nit');

        $companiesData = collect($companies)->map(fn (Company $c) => [
            'nit' => $c->nit,
            'name' => $c->commercial_name,
            'status' => $c->status,
            'linked' => ($memberships->get($c->nit)?->status ?? 'inactive') === 'active',
            'logo_url' => SignedAssetUrl::for($c->logo_path),
        ])->values()->all();

        $companyNits = array_column($companiesData, 'nit');
        $enrollmentStep = $this->resolveEnrollmentStep($user, $companyNits);

        // Smart default cuando no se pidió empresa activa explícita: preferir
        // la primera empresa `active` linked sobre cualquier `past_due` o
        // `suspended`. Reduce el "sidebar tachado" cuando el usuario tiene
        // varias empresas y termina en una bloqueada por accidente.
        //
        // Si el caller pasó un nit específico (ej. selectCompany cuando el
        // dueño eligió la suspended para entrar a /billing), respetamos esa
        // elección — el smart default NO sobreescribe la intención explícita.
        if ($activeCompanyNit === null) {
            $smartDefault = collect($companiesData)
                ->first(fn (array $c) => ($c['linked'] ?? false) && ($c['status'] ?? null) === 'active');
            if ($smartDefault !== null) {
                $activeCompanyNit = $smartDefault['nit'];
            }
        }

        $linkedEntry = collect($companiesData)->first(fn (array $c) => $c['nit'] === $activeCompanyNit);
        $isLinked = $linkedEntry !== null && ($linkedEntry['linked'] ?? false);

        if (! $isLinked) {
            $activeCompanyNit = null;
        }

        // Rol + permisos se resuelven desde BD vía la fuente de verdad única
        // (#268): `FeaturePermissionService::resolveRoleAndPermissions`. El
        // mismo método lo consume `BootstrapService` en cada carga, de modo que
        // el sidebar nunca diverge de lo que el backend valida en vivo.
        $resolved = app(FeaturePermissionService::class)->resolveRoleAndPermissions($user, $activeCompanyNit);
        $role = $resolved['role'];
        $permissions = $resolved['permissions'];

        $activeCompanyData = collect($companiesData)
            ->first(fn (array $c) => $c['nit'] === $activeCompanyNit);

        $isOwner = ($role['is_system'] ?? false) === true;
        [$branchesData, $resolvedBranchId, $activeBranchData] = $this->resolveBranchContext($user, $activeCompanyNit, $activeBranchId, $isOwner);

        $iat = time();
        $exp = $iat + $this->ttlSeconds;
        $timezone = config('app.timezone', 'UTC');

        $payload = [
            'sub' => (string) $user->id,
            'email' => $user->email,
            'enrollment_step' => $enrollmentStep,
            'active_company_nit' => $activeCompanyNit,
            'active_company_name' => $activeCompanyData['name'] ?? null,
            'active_company_logo_url' => $activeCompanyData['logo_url'] ?? null,
            'active_branch_id' => $resolvedBranchId,
            'active_branch_name' => $activeBranchData['name'] ?? null,
            'active_branch_slug' => $activeBranchData['slug'] ?? null,
            'branches' => $branchesData,
            'role' => $role,
            'permissions' => $permissions,
            'companies' => $companiesData,
            'iat' => $iat,
            'exp' => $exp,
            // auth_time: instante de autenticación original. Inmutable a través
            // de los reissue: marca el inicio de la sesión para el tope absoluto.
            'auth_time' => $iat,
            'issued_at' => Carbon::createFromTimestamp($iat, $timezone)->toIso8601String(),
            'expires_at' => Carbon::createFromTimestamp($exp, $timezone)->toIso8601String(),
        ];

        $encryptedPayload = $this->encryptPayload($payload);

        $header = $this->base64UrlEncode(json_encode(['alg' => self::ALGORITHM, 'typ' => 'JWT']));
        $body = $this->base64UrlEncode(json_encode(['data' => $encryptedPayload]));
        $signature = $this->sign("{$header}.{$body}");

        $token = "{$header}.{$body}.{$signature}";

        UserActiveToken::updateOrCreate(
            ['user_id' => $user->id],
            ['signature' => $signature, 'expires_at' => Carbon::createFromTimestamp($exp)]
        );

        return $token;
    }

    /**
     * Invalidate the currently active JWT session for a given user.
     * Used when an admin deactivates the user's membership in a company.
     */
    public function invalidateUserActiveSession(string $userId): void
    {
        $record = UserActiveToken::where('user_id', $userId)->first();
        if ($record === null) {
            return;
        }

        $remainingTtl = max(1, (int) now()->diffInSeconds($record->expires_at, false));

        if ($remainingTtl > 0 && config('auth.jwt_blacklist_enabled', false)) {
            Cache::put("jwt_blacklist:{$record->signature}", true, $remainingTtl);
        }

        $record->delete();
    }

    /**
     * Verify and decode a JWT, returning the decrypted payload.
     *
     * @return array{sub: string, email: string, enrollment_step: string, active_company_nit: ?string, active_company_name: ?string, active_company_logo_url: ?string, role: ?array{id: int, name: string, is_system: bool}, permissions: array<string>, companies: array<array{nit: string, name: string, status: string, logo_url: ?string}>, iat: int, exp: int, issued_at: string, expires_at: string}
     *
     * @throws RuntimeException
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid JWT structure.');
        }

        [$header, $body, $signature] = $parts;

        $expectedSignature = $this->sign("{$header}.{$body}");
        if (! hash_equals($expectedSignature, $signature)) {
            throw new RuntimeException('JWT signature verification failed.');
        }

        $bodyDecoded = json_decode($this->base64UrlDecode($body), true);
        if (! isset($bodyDecoded['data'])) {
            throw new RuntimeException('JWT body is malformed.');
        }

        $payload = $this->decryptPayload($bodyDecoded['data']);

        if (time() > $payload['exp']) {
            throw new RuntimeException('JWT has expired.');
        }

        // Tope de vida ABSOLUTO: aunque el refresh deslizante mantenga exp vivo,
        // una sesión no puede superar auth_time + jwt_max_lifetime. Pasado el
        // tope se rechaza el token y se fuerza re-login. Tokens legacy sin
        // auth_time caen al iat (se regularizan en el primer reissue).
        $authTime = (int) ($payload['auth_time'] ?? $payload['iat']);
        if (time() > $authTime + $this->maxLifetimeSeconds) {
            throw new RuntimeException('JWT session exceeded max lifetime.');
        }

        if ($this->isBlacklisted($signature)) {
            throw new RuntimeException('JWT has been invalidated.');
        }

        return $payload;
    }

    /**
     * Re-issue an existing verified payload with fresh timestamps, extending the session window.
     *
     * @param  array<string, mixed>  $payload
     */
    public function reissue(array $payload): string
    {
        $iat = time();
        $timezone = config('app.timezone', 'UTC');

        // auth_time es inmutable: si el token es legacy (pre-tope) y no lo trae,
        // lo sembramos con el iat actual para arrancar el reloj del tope desde ya.
        $authTime = (int) ($payload['auth_time'] ?? $iat);

        // El refresh nunca extiende exp más allá del tope absoluto de sesión.
        $exp = min($iat + $this->ttlSeconds, $authTime + $this->maxLifetimeSeconds);

        $payload['iat'] = $iat;
        $payload['exp'] = $exp;
        $payload['auth_time'] = $authTime;
        $payload['issued_at'] = Carbon::createFromTimestamp($iat, $timezone)->toIso8601String();
        $payload['expires_at'] = Carbon::createFromTimestamp($exp, $timezone)->toIso8601String();

        $encryptedPayload = $this->encryptPayload($payload);

        $header = $this->base64UrlEncode(json_encode(['alg' => self::ALGORITHM, 'typ' => 'JWT']));
        $body = $this->base64UrlEncode(json_encode(['data' => $encryptedPayload]));
        $signature = $this->sign("{$header}.{$body}");

        $token = "{$header}.{$body}.{$signature}";

        UserActiveToken::updateOrCreate(
            ['user_id' => (string) $payload['sub']],
            ['signature' => $signature, 'expires_at' => Carbon::createFromTimestamp($exp)]
        );

        return $token;
    }

    /**
     * Add a token to the blacklist so it cannot be used again.
     */
    public function invalidate(string $token): void
    {
        if (! config('auth.jwt_blacklist_enabled', false)) {
            return;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return;
        }

        $signature = $parts[2];

        try {
            $payload = $this->verify($token);
            $ttl = max(1, $payload['exp'] - time());
        } catch (RuntimeException) {
            return;
        }

        Cache::put("jwt_blacklist:{$signature}", true, $ttl);
    }

    private function isBlacklisted(string $signature): bool
    {
        if (! config('auth.jwt_blacklist_enabled', false)) {
            return false;
        }

        return Cache::has("jwt_blacklist:{$signature}");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encryptPayload(array $payload): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $key = $this->deriveEncryptionKey();

        // Gzip antes de cifrar: el payload contiene listas redundantes
        // (companies, branches, permissions) que comprimen al 30-40% del JSON
        // original. Sin esto, cuentas con multiples empresas y permisos full
        // generan JWTs >4 KB que, una vez como cookie con atributos, exceden
        // el limite de 4096 bytes de Chrome/Firefox/Safari y el browser los
        // descarta silenciosamente. Prefijo "GZ\x01" permite distinguir
        // payloads nuevos (comprimidos) de viejos (JSON plano) durante verify
        // sin romper retrocompatibilidad mientras expira el TTL anterior.
        $json = (string) json_encode($payload);
        $compressed = "GZ\x01".gzdeflate($json, 9);

        $encrypted = openssl_encrypt(
            $compressed,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return $this->base64UrlEncode($iv.$encrypted);
    }

    /**
     * @return array<string, mixed>
     */
    private function decryptPayload(string $encryptedData): array
    {
        $raw = $this->base64UrlDecode($encryptedData);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($raw, 0, $ivLength);
        $ciphertext = substr($raw, $ivLength);
        $key = $this->deriveEncryptionKey();

        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new RuntimeException('JWT payload decryption failed.');
        }

        // Retrocompat: los tokens emitidos antes del fix no tienen gzip ni
        // el marker "GZ\x01". Si detectamos el marker, descomprimimos; si no,
        // tratamos como JSON plano. Una vez que todos los tokens previos al
        // fix expiren (TTL = 60 min), el branch JSON-plano se puede eliminar.
        if (str_starts_with($decrypted, "GZ\x01")) {
            $inflated = gzinflate(substr($decrypted, 3));
            if ($inflated === false) {
                throw new RuntimeException('JWT payload decompression failed.');
            }

            return json_decode($inflated, true);
        }

        return json_decode($decrypted, true);
    }

    private function deriveEncryptionKey(): string
    {
        // Derive a 32-byte key from the configured secret using SHA-256
        return hash('sha256', $this->encryptionKey, true);
    }

    private function sign(string $data): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $data, $this->signingKey, true));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    /**
     * Resuelve la sede activa y la lista de sedes disponibles para la empresa activa.
     *
     * Reglas:
     *  - Si no hay empresa activa, no hay sede activa.
     *  - Si el usuario tiene 1 sede accesible en la empresa, se auto-selecciona.
     *  - Si tiene N, queda null (el frontend redirige a /select-branch).
     *  - Si pasaron $activeBranchId explícito y el usuario tiene acceso, se respeta.
     *
     * @return array{0: list<array{id: string, name: string, slug: string, is_default: bool, address: ?string, city: ?string}>, 1: ?string, 2: ?array{id: string, name: string, slug: string, is_default: bool}}
     */
    private function resolveBranchContext(User $user, ?string $activeCompanyNit, ?string $activeBranchId, bool $isOwner = false): array
    {
        if ($activeCompanyNit === null) {
            return [[], null, null];
        }

        // Multi-sede (#117): owners (is_system) ven todas las sedes activas de la
        // empresa; el resto sólo las del pivot branch_users.
        $branches = $isOwner
            ? Branch::query()
                ->where('company_nit', $activeCompanyNit)
                ->whereNull('archived_at')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'is_default', 'address', 'city'])
            : $user->accessibleBranches($activeCompanyNit)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['branches.id', 'branches.name', 'branches.slug', 'branches.is_default', 'branches.address', 'branches.city']);

        $branchesData = $branches->map(fn (Branch $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'slug' => $b->slug,
            'is_default' => (bool) $b->is_default,
            'address' => $b->address,
            'city' => $b->city,
        ])->values()->all();

        $resolvedBranchId = null;

        if ($activeBranchId !== null) {
            $match = $branches->firstWhere('id', $activeBranchId);
            if ($match !== null) {
                $resolvedBranchId = $match->id;
            }
        }

        // Fallback de selección automática de sede activa (#117 + #122 hotfix):
        //  1. Si el usuario sólo tiene UNA sede accesible, esa es la activa.
        //  2. Si tiene N>1 sedes, intentamos resolver a la marcada como
        //     is_default=true. Cada empresa tiene exactamente una default
        //     (garantizado por trigger DB + onboarding), y el orderByDesc
        //     superior la coloca primero — así el switcher del sidebar
        //     siempre aparece tras el primer login en lugar de quedar null
        //     y disparar 422 NO_ACTIVE_BRANCH en EnsureBranchAccess.
        //     El usuario puede cambiar manualmente vía POST /auth/branch.
        if ($resolvedBranchId === null && $branches->count() >= 1) {
            $default = $branches->firstWhere('is_default', true) ?? $branches->first();
            $resolvedBranchId = $default->id;
        }

        $activeBranchData = $resolvedBranchId
            ? collect($branchesData)->firstWhere('id', $resolvedBranchId)
            : null;

        return [$branchesData, $resolvedBranchId, $activeBranchData];
    }

    /** @param  array<string>  $companyNits */
    private function resolveEnrollmentStep(User $user, array $companyNits): string
    {
        if ($user->isPendingEnrollment()) {
            return 'user';
        }

        if (empty($companyNits)) {
            return 'company';
        }

        return 'complete';
    }
}

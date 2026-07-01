<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Enrollment\InvitationAcceptanceService;
use App\Services\JwtService;
use App\Support\PostLoginRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * Maneja el flujo OAuth de Google: redirección y callback.
 *
 * redirect(): inicia el flujo OAuth stateless hacia Google.
 * callback(): obtiene/crea el usuario y emite un JWT temporal para continuar el enrollment o ir al dashboard.
 *
 * Reglas de redirección post-callback:
 *  - Usuario nuevo o sin enrollment completo → /enrollment/user.
 *  - Usuario con 1 sola empresa active → dashboard (auto-entrada).
 *  - Usuario con 1 sola empresa NO active (past_due/suspended/etc.) → company-selector
 *    (el frontend permite elegir la única; mantiene el step de "confirmación explícita").
 *  - Usuario con N > 1 empresas (cualquier status) → SIEMPRE company-selector.
 *
 * La regla N > 1 es estricta: aunque sólo una de las N esté `active`, el usuario
 * elige explícitamente para evitar entrar a una empresa "por descarte" tras
 * dejar otras inactivas. Antes el callback auto-entraba cuando había 1 active
 * entre varias — generaba surprise login a la empresa equivocada.
 *
 * @env GOOGLE_CLIENT_ID — App ID de OAuth Google
 * @env GOOGLE_CLIENT_SECRET — App Secret de OAuth Google
 * @env GOOGLE_REDIRECT_URI — URL de callback registrada en Google Console
 */
class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly AuditService $auditService,
        private readonly InvitationAcceptanceService $invitationAcceptance,
    ) {}

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (InvalidStateException $e) {
            return redirect()->route('home')->withErrors(['oauth' => 'La sesión OAuth expiró o el callback fue manipulado. Inténtalo de nuevo.']);
        } catch (Throwable $e) {
            Log::warning('Google OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect()->route('home')->withErrors(['oauth' => 'No fue posible iniciar sesión con Google. Inténtalo de nuevo.']);
        }

        if (config('auth.enforce_gsuite_domain', false)) {
            $hostedDomain = $googleUser->getRaw()['hd'] ?? null;
            if ($hostedDomain === null) {
                return redirect()->route('home')->withErrors(['oauth' => 'Solo se permiten cuentas G Suite.']);
            }
        }

        $isNewUser = false;
        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        if ($user === null) {
            [$firstName, $lastName] = $this->splitGoogleName($googleUser);

            $user = User::create([
                'google_id' => $googleUser->getId(),
                // `name` es columna generada: se compone de first_name + last_name.
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $googleUser->getEmail(),
                'status' => 'pending_enrollment',
            ]);
            $isNewUser = true;

            $this->auditService->log('user.created', $user, $user);
        } else {
            $user->update(['google_id' => $googleUser->getId()]);
        }

        $this->auditService->log('auth.login', $user, $user);

        if ($isNewUser || $user->isPendingEnrollment()) {
            $token = $this->jwtService->issue($user);

            return redirect()->route('enrollment.user')
                ->withCookie($this->jwtService->buildCookie($token));
        }

        // Usuario YA enrolado: aceptar acá cualquier invitación pendiente a su
        // correo. El auto-accept de invitaciones sólo corría en el signup nuevo
        // (`/enrollment/user`); sin esto, invitar a una cuenta existente dejaba
        // la invitación `pending` para siempre y el usuario nunca entraba a la
        // empresa nueva. Cumple la promesa del correo ("apenas entres, tu acceso
        // queda activo").
        $this->invitationAcceptance->acceptAllPendingFor($user);

        $companies = $user->companies()->get();

        if ($companies->count() === 1) {
            // Una sola empresa: auto-entrada cuando está active, selector en
            // cualquier otro status (past_due/suspended/etc.) — el selector
            // muestra una sola tarjeta y el usuario confirma explícitamente.
            $company = $companies->first();
            $token = $this->jwtService->issue($user, [$company], $company->status === 'active' ? $company->nit : null);

            // Si el usuario es courier-only (rol Domiciliario sin
            // permisos de admin), entra directo a `/my-deliveries` en lugar
            // de dashboard.
            $route = $company->status === 'active'
                ? PostLoginRedirect::routeNameForUser($user->id, $company->nit)
                : 'auth.company-selector';

            return redirect()->route($route)
                ->withCookie($this->jwtService->buildCookie($token));
        }

        // Más de una empresa: SIEMPRE pasa por el selector, sin importar
        // cuántas estén `active`. El usuario tiene que elegir conscientemente
        // a cuál entra — evita que un cambio de status (otra empresa marcada
        // inactive por ops) provoque login automático a la única active
        // restante sin que el dueño se entere.
        $token = $this->jwtService->issue($user, $companies, null);

        return redirect()->route('auth.company-selector')
            ->withCookie($this->jwtService->buildCookie($token));
    }

    /**
     * Obtiene nombres/apellidos del perfil de Google. Prefiere los campos
     * estructurados `given_name`/`family_name` del userinfo; si no vienen,
     * parte `getName()` por el primer espacio (primer token = nombres, resto =
     * apellidos). El enrollment posterior deja al usuario corregirlos.
     *
     * @return array{0: string, 1: string}
     */
    private function splitGoogleName(\Laravel\Socialite\Contracts\User $googleUser): array
    {
        $raw = $googleUser->getRaw();
        $given = trim((string) ($raw['given_name'] ?? ''));
        $family = trim((string) ($raw['family_name'] ?? ''));

        if ($given !== '' || $family !== '') {
            return [$given, $family];
        }

        $fullName = trim((string) $googleUser->getName());
        if ($fullName === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $fullName, 2) ?: [$fullName];

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}

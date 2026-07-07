<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Enrollment\InvitationAcceptanceService;
use App\Services\JwtService;
use App\Support\PostLoginRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    /**
     * Nombre de la cookie que transporta el `state` anti-CSRF del flujo OAuth.
     */
    private const OAUTH_STATE_COOKIE = 'oauth_state';

    public function redirect(): RedirectResponse
    {
        // CIBER-10: `stateless()` omite la validación de `state` (login CSRF).
        // Generamos un `state` propio, lo guardamos en una cookie HttpOnly de
        // vida corta (double-submit) y lo mandamos a Google. No depende de la
        // sesión server-side → N-instance safe (CLAUDE.md §12).
        // `prompt=select_account` fuerza el selector de cuentas de Google:
        // sin él, con una sola sesión activa Google hace auto-login silencioso
        // y el usuario nunca puede elegir con qué Gmail entrar.
        $state = Str::random(40);

        $secure = (bool) config('session.secure', ! app()->isLocal());

        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => $state, 'prompt' => 'select_account'])
            ->redirect()
            ->withCookie(cookie(
                name: self::OAUTH_STATE_COOKIE,
                value: $state,
                minutes: 10,
                path: '/',
                domain: config('session.domain'),
                secure: $secure,
                httpOnly: true,
                raw: false,
                sameSite: 'lax',
            ));
    }

    public function callback(Request $request): RedirectResponse
    {
        // CIBER-10: valida el `state` echo de Google contra la cookie emitida en
        // redirect(). hash_equals evita timing; mismatch/ausencia → rechazo.
        $expectedState = (string) $request->cookie(self::OAUTH_STATE_COOKIE, '');
        $returnedState = (string) $request->query('state', '');

        if ($expectedState === '' || $returnedState === '' || ! hash_equals($expectedState, $returnedState)) {
            return redirect()->route('home')
                ->withErrors(['oauth' => 'La sesión OAuth expiró o el callback fue manipulado. Inténtalo de nuevo.'])
                ->withoutCookie(self::OAUTH_STATE_COOKIE);
        }

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

        // CIBER-08: el identificador primario es `google_id`. El match por email
        // solo se usa para VINCULAR una cuenta preexistente (invitación) y exige
        // que Google haya verificado el email, y que la cuenta no esté ya
        // vinculada a OTRO `google_id` (evita adopción/takeover de cuenta ajena).
        $emailVerified = filter_var(
            $googleUser->getRaw()['email_verified'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $isNewUser = false;
        $user = User::where('google_id', $googleUser->getId())->first();

        if ($user === null && $emailVerified) {
            $byEmail = User::where('email', $googleUser->getEmail())->first();
            if ($byEmail !== null) {
                // Ya vinculada a otra identidad Google → conflicto, no adoptar.
                if (! empty($byEmail->google_id) && $byEmail->google_id !== $googleUser->getId()) {
                    Log::warning('auth.google.account_link_conflict', [
                        'email' => $googleUser->getEmail(),
                        'existing_user_id' => $byEmail->id,
                    ]);

                    return redirect()->route('home')->withErrors([
                        'oauth' => 'Esta cuenta ya está vinculada a otro acceso de Google. Contacta soporte.',
                    ]);
                }

                $user = $byEmail;
            }
        }

        if ($user === null) {
            if (! $emailVerified) {
                return redirect()->route('home')->withErrors([
                    'oauth' => 'Tu correo de Google no está verificado. Verifícalo e inténtalo de nuevo.',
                ]);
            }

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
        } elseif (empty($user->google_id)) {
            // Primer login de una cuenta invitada: se vincula su google_id.
            $user->update(['google_id' => $googleUser->getId()]);
        }

        // Google ya verificó el correo (guard $emailVerified arriba) — se
        // PERSISTE para el gate de verificación del enrollment de empresa
        // (acceso dual). Cubre también el backfill de cuentas Google previas
        // que nunca guardaron email_verified_at.
        if ($emailVerified && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
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

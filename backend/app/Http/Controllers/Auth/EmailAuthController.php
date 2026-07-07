<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Notifications\AccountAlreadyExistsNotification;
use App\Notifications\VerifyEmailAddressNotification;
use App\Services\AuditService;
use App\Services\Auth\PostLoginService;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Acceso con correo/contraseña — complementario al OAuth de Google.
 *
 * Una cuenta = un correo: `google_id` y `password` son dos credenciales de
 * la MISMA fila `users`. Una cuenta creada con Google fija contraseña vía
 * "olvidé mi contraseña" (PasswordResetController) y desde ahí ambos
 * métodos funcionan; el vínculo inverso (registro por email → luego entra
 * con Google) lo hace el callback de Google al matchear por correo
 * verificado.
 *
 * Anti-abuso (nativo Laravel):
 *  - login: lockout RateLimiter por email+IP (5 intentos / 60s, patrón
 *    Fortify) + throttle global por IP en la ruta. Mensaje de credenciales
 *    SIEMPRE genérico (anti-enumeración).
 *  - register: throttle por IP en la ruta + honeypot `website` (éxito falso
 *    sin crear nada) + Password::defaults() con uncompromised (HIBP).
 *  - resend: RateLimiter 3 / 10 min por usuario (anti mail-bombing SES).
 */
class EmailAuthController extends Controller
{
    /** Minutos de validez del enlace de verificación firmado. */
    private const VERIFY_LINK_MINUTES = 60;

    public function __construct(
        private readonly JwtService $jwtService,
        private readonly AuditService $auditService,
        private readonly PostLoginService $postLogin,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($credentials['email']));
        $lockoutKey = 'auth-login:'.sha1($email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($lockoutKey, 5)) {
            $seconds = RateLimiter::availableIn($lockoutKey);
            $this->auditService->log('auth.login.locked', null, null, [
                'email' => $email,
                'ip' => $request->ip(),
                'retry_in_seconds' => $seconds,
            ], $request);

            return response()->json([
                'message' => "Demasiados intentos. Vuelve a intentarlo en {$seconds} segundos.",
            ], 429);
        }

        $user = User::query()->where('email', $email)->first();

        // Timing constante (anti-enumeración): SIEMPRE se corre exactamente un
        // bcrypt, exista o no la cuenta. Si hay contraseña se verifica; si el
        // correo no existe o es cuenta Google (password null), se hace un
        // `Hash::make` de descarte — mismo costo bcrypt al rounds configurado
        // de la app (auto-calibrado, no depende de un hash fijo) y se descarta.
        // Sin esto, la ausencia del bcrypt en el camino "sin contraseña"
        // filtraba por tiempo qué correos tienen cuenta con contraseña.
        if ($user !== null && $user->password !== null) {
            $passwordMatches = Hash::check($credentials['password'], $user->password);
        } else {
            Hash::make($credentials['password']);
            $passwordMatches = false;
        }

        if (! $passwordMatches) {
            RateLimiter::hit($lockoutKey, 60);

            return response()->json([
                'message' => 'Credenciales inválidas.',
                'errors' => ['email' => ['Credenciales inválidas.']],
            ], 422);
        }

        RateLimiter::clear($lockoutKey);

        $this->auditService->log('auth.login', $user, $user, ['method' => 'password'], $request);

        $result = $this->postLogin->resolve($user);

        return response()
            ->json(['authenticated' => true, 'redirect' => $result['redirect']])
            ->withCookie($this->jwtService->buildCookie($result['token']));
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        // Respuesta ÚNICA para TODOS los caminos (alta nueva, correo ya
        // existente, honeypot). Anti-enumeración: quien registra no puede
        // distinguir si el correo ya tenía cuenta — mismo cuerpo, mismo status
        // y SIN cookie de sesión (un Set-Cookie solo en el alta nueva sería un
        // oráculo: "cookie presente = correo libre"). Por eso el registro ya
        // NO auto-loguea; tras verificar el correo, el usuario entra por
        // /login y el flujo continúa hacia el enrollment.
        $genericResponse = response()->json([
            'registered' => true,
            'redirect' => '/verify-email',
            'email' => Str::lower(trim((string) $request->input('email', ''))),
        ], 201);

        // Honeypot: humanos nunca ven ni llenan `website`.
        if (trim((string) $request->input('website', '')) !== '') {
            Log::info('auth.register.honeypot', ['ip' => $request->ip(), 'ua' => $request->userAgent()]);

            return $genericResponse;
        }

        $validated = $request->validated();
        $email = Str::lower(trim($validated['email']));

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            // Timing constante: el alta nueva corre un bcrypt (Hash::make); acá
            // corremos otro de descarte para no delatar por tiempo que el correo
            // ya existía.
            Hash::make($validated['password']);

            if ($existing->email_verified_at === null) {
                // Cuenta a medio registrar (nunca verificó): reenviar el enlace
                // en vez de "ya tienes cuenta" — es la misma persona terminando
                // su alta.
                $this->sendVerificationLink($existing);
            } else {
                // Cuenta ya operativa: avisar al titular real por correo. NO se
                // crea nada ni se toca su cuenta.
                $existing->notify(new AccountAlreadyExistsNotification);
            }

            return $genericResponse;
        }

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            // `name` es columna generada (first_name + last_name): no se escribe.
            'email' => $email,
            'password' => Hash::make($validated['password']),
            'status' => 'pending_enrollment',
        ]);

        $this->auditService->log('user.created', $user, $user, ['method' => 'password'], $request);

        $this->sendVerificationLink($user);

        return $genericResponse;
    }

    /**
     * Estado de verificación del usuario autenticado. Lo consume el poll de
     * la pantalla /verify-email para avanzar solo al enrollment.
     */
    public function verificationStatus(Request $request): JsonResponse
    {
        $user = $this->userFromJwt($request);

        // Cuentas Google legacy sin email_verified_at persistido: Google ya
        // verificó — backfill idempotente para que el chequeo del frontend no
        // las mande a "verifica tu correo" por error.
        $user->ensureGoogleEmailVerified();

        return response()->json([
            'email' => $user->email,
            'verified' => $user->email_verified_at !== null,
        ]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $this->userFromJwt($request);

        if ($user->email_verified_at !== null) {
            return response()->json(['verified' => true, 'message' => 'Tu correo ya está verificado.']);
        }

        // Anti mail-bombing: 3 reenvíos cada 10 minutos por usuario. Protege
        // el costo y la reputación del remitente SES.
        $key = 'auth-verify-resend:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "Ya te enviamos varios enlaces. Revisa tu bandeja (y spam) o reintenta en {$seconds} segundos.",
            ], 429);
        }
        RateLimiter::hit($key, 600);

        $this->sendVerificationLink($user);

        return response()->json(['sent' => true, 'message' => 'Te enviamos un nuevo enlace de verificación.']);
    }

    /**
     * Reenvío de verificación SIN sesión, por correo — lo usa la pantalla
     * /verify-email justo después del registro (que ya no auto-loguea).
     *
     * Anti-enumeración: respuesta SIEMPRE genérica, exista o no el correo /
     * esté o no verificado. Solo envía si hay una cuenta sin verificar. El
     * throttle de ruta (por IP) + un lock por correo evitan mail-bombing.
     */
    public function resendVerificationPublic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $generic = response()->json([
            'sent' => true,
            'message' => 'Si tu correo está pendiente de verificación, te enviamos un nuevo enlace.',
        ]);

        // Límite por correo (además del throttle por IP de la ruta): 3 / 10 min.
        $key = 'auth-verify-resend-pub:'.sha1($email);
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return $generic;
        }
        RateLimiter::hit($key, 600);

        $user = User::query()->where('email', $email)->first();
        if ($user !== null && $user->email_verified_at === null) {
            $this->sendVerificationLink($user);
        }

        return $generic;
    }

    private function sendVerificationLink(User $user): void
    {
        // URL firmada temporal sobre el host del API. El hash sha1(email) ata
        // el enlace al correo vigente (si cambia el correo, el enlace viejo
        // deja de servir) — mismo patrón del VerifyEmail nativo de Laravel.
        $verifyUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(self::VERIFY_LINK_MINUTES),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $user->notify(new VerifyEmailAddressNotification($verifyUrl, self::VERIFY_LINK_MINUTES));
    }

    private function userFromJwt(Request $request): User
    {
        $sub = $request->attributes->get('jwt_payload')['sub'] ?? null;

        return User::query()->findOrFail($sub);
    }
}

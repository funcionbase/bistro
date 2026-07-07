<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

/**
 * Recuperación / fijado de contraseña vía enlace por correo.
 *
 * Además de recuperar una contraseña olvidada, es el mecanismo con el que
 * una cuenta creada con Google FIJA contraseña por primera vez (prueba
 * posesión del correo) y habilita el acceso dual.
 *
 * Anti-enumeración: `forgot` responde SIEMPRE el mismo mensaje, exista o no
 * el correo. Anti mail-bombing: RateLimiter 3 / 10 min por correo+IP además
 * del throttle por IP de la ruta (el broker ya trae su propio throttle de
 * 60s entre correos al mismo usuario).
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $genericResponse = response()->json([
            'sent' => true,
            'message' => 'Si el correo existe, te enviamos instrucciones para restablecer la contraseña.',
        ]);

        $key = 'auth-forgot:'.sha1($email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) {
            // Mismo cuerpo genérico: un atacante no distingue lockout de envío.
            return $genericResponse;
        }
        RateLimiter::hit($key, 600);

        // sendResetLink dispara User::sendPasswordResetNotification (enlace al
        // SPA). Ignoramos el status: la respuesta no debe revelar existencia.
        Password::sendResetLink(['email' => $email]);

        return $genericResponse;
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::reset(
            [
                'email' => Str::lower(trim($validated['email'])),
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (User $user) use ($validated, $request) {
                $user->forceFill([
                    'password' => Hash::make($validated['password']),
                    // El reset probó posesión del correo — cuenta como
                    // verificación (habilita el registro de empresa).
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                $this->auditService->log('auth.password_reset', $user, $user, [], $request);
            },
        );

        if ($status !== Password::PasswordReset) {
            // Mensaje ÚNICO para cualquier fallo: sin distinguir
            // passwords.user (correo inexistente) de passwords.token (token
            // inválido). Echoar `__($status)` filtraba la existencia del correo
            // — un atacante mandaba un token cualquiera y leía si el mensaje
            // decía "usuario no encontrado" vs "token inválido".
            return response()->json([
                'message' => 'El enlace ya no es válido o expiró. Pide uno nuevo desde "Olvidé mi contraseña".',
            ], 422);
        }

        return response()->json([
            'reset' => true,
            'redirect' => '/login?reset=1',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\JwtService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Verificación de correo vía enlace firmado — pública, sin sesión.
 *
 * La versión Breeze anterior exigía sesión web de Laravel, que no existe en
 * esta app (la sesión es un JWT en cookie). El middleware `signed` valida
 * firma + expiración de la URL; el `hash` (sha1 del email) ata el enlace al
 * correo vigente del usuario.
 *
 * Continuidad del flujo (requisito): tras verificar, si el navegador trae la
 * cookie JWT válida se redirige DIRECTO al paso pendiente del enrollment
 * (usuario → empresa); si el enlace se abrió en otro navegador/dispositivo,
 * va a /login?verified=1 y el post-login retoma el flujo.
 */
class VerifyEmailController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly AuditService $auditService,
    ) {}

    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $user = User::query()->find($id);

        if ($user === null || ! hash_equals(sha1($user->email), $hash)) {
            return redirect()->away($frontend.'/login?verify_error=1');
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->auditService->log('auth.email_verified', $user, $user, [], $request);
        }

        return redirect()->away($frontend.$this->continuePath($request, $user));
    }

    /**
     * Paso siguiente del flujo según el estado del usuario, solo si la cookie
     * JWT del navegador corresponde al MISMO usuario del enlace (abrirlo en
     * otro navegador o con otra sesión activa manda a login).
     */
    private function continuePath(Request $request, User $user): string
    {
        $token = $this->jwtService->extractTokenFromRequest($request);
        if ($token === null) {
            return '/login?verified=1';
        }

        try {
            $payload = $this->jwtService->verify($token);
        } catch (Throwable) {
            return '/login?verified=1';
        }

        if (($payload['sub'] ?? null) !== $user->id) {
            return '/login?verified=1';
        }

        if ($user->isPendingEnrollment()) {
            return '/enrollment/user';
        }

        return $user->companies()->exists() ? '/dashboard' : '/enrollment/company';
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Enrollment\InvitationAcceptanceService;
use App\Services\JwtService;
use App\Support\PostLoginRedirect;

/**
 * Decisión post-login para el acceso con correo/contraseña.
 *
 * Espeja las reglas del callback de Google (`GoogleAuthController::callback`)
 * pero devuelve rutas del SPA (el login por email es un POST JSON, no un
 * redirect server-side):
 *
 *  - Correo sin verificar (registro por email) → /verify-email.
 *  - `pending_enrollment` → /enrollment/user (retoma el paso pendiente).
 *  - Activo sin empresas → /enrollment/company (retoma el registro de empresa).
 *  - 1 empresa active → dashboard (o /my-deliveries para courier-only).
 *  - 1 empresa no-active o N > 1 → /auth/company-selector (elección explícita,
 *    misma regla estricta del flujo Google).
 */
class PostLoginService
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly InvitationAcceptanceService $invitationAcceptance,
    ) {}

    /**
     * @return array{token: string, redirect: string}
     */
    public function resolve(User $user): array
    {
        // Sin verificar: JWT mínimo para poder reenviar el enlace y hacer poll
        // del estado, pero el destino es la pantalla "verifica tu correo".
        // El registro de empresa está gateado server-side de todas formas.
        if ($user->email_verified_at === null) {
            return [
                'token' => $this->jwtService->issue($user),
                'redirect' => '/verify-email',
            ];
        }

        if ($user->isPendingEnrollment()) {
            return [
                'token' => $this->jwtService->issue($user),
                'redirect' => '/enrollment/user',
            ];
        }

        // Paridad con el callback de Google: aceptar invitaciones pendientes
        // al correo en cada login de un usuario ya enrolado.
        $this->invitationAcceptance->acceptAllPendingFor($user);

        $companies = $user->companies()->get();

        // Enrolado pero sin empresa (salió a mitad del flujo): retoma el
        // registro de empresa directamente.
        if ($companies->isEmpty()) {
            return [
                'token' => $this->jwtService->issue($user),
                'redirect' => '/enrollment/company',
            ];
        }

        if ($companies->count() === 1) {
            $company = $companies->first();
            $token = $this->jwtService->issue($user, [$company], $company->status === 'active' ? $company->nit : null);

            if ($company->status !== 'active') {
                return ['token' => $token, 'redirect' => '/auth/company-selector'];
            }

            $redirect = PostLoginRedirect::routeNameForUser($user->id, $company->nit) === 'deliveries.mine'
                ? '/my-deliveries'
                : '/dashboard';

            return ['token' => $token, 'redirect' => $redirect];
        }

        return [
            'token' => $this->jwtService->issue($user, $companies, null),
            'redirect' => '/auth/company-selector',
        ];
    }
}

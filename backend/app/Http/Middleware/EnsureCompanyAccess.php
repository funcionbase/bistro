<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica que el active_company_nit del JWT corresponda a una membresía activa del usuario.
 *
 * Lee: jwt_payload (inyectado por ValidateJwt).
 * Inyecta en request: active_company_nit, user_role, company_role_id, company_role_is_system.
 * Registra en auditoría (company.unauthorized_access) los intentos de acceso sin membresía.
 * Retorna 403 si el usuario no tiene membresía en la empresa activa del JWT.
 */
class EnsureCompanyAccess
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $payload = $request->attributes->get('jwt_payload');

        $activeCompanyNit = $payload['active_company_nit'] ?? null;

        if ($activeCompanyNit === null) {
            return response()->json(['message' => 'No has seleccionado una empresa activa.'], 403);
        }

        $userId = $payload['sub'] ?? null;
        $membership = CompanyUser::with('role')
            ->where('user_id', $userId)
            ->where('company_nit', $activeCompanyNit)
            ->first();

        if ($membership === null) {
            $user = User::find($userId);
            $company = Company::where('nit', $activeCompanyNit)->first();

            if ($user) {
                $this->auditService->log('company.unauthorized_access', $user, $company, [
                    'company_nit' => $activeCompanyNit,
                    'path' => $request->path(),
                ], $request);
            }

            return response()->json(['message' => 'No tienes acceso a esta empresa.'], 403);
        }

        $userRole = match (true) {
            $membership->role?->is_system === true => 'owner',
            strtolower($membership->role?->name ?? '') === 'admin' => 'admin',
            default => 'member',
        };

        $request->attributes->set('active_company_nit', $activeCompanyNit);
        $request->attributes->set('user_role', $userRole);
        $request->attributes->set('company_role_id', $membership->company_role_id);
        $request->attributes->set('company_role_is_system', $membership->role?->is_system ?? false);

        return $next($request);
    }
}

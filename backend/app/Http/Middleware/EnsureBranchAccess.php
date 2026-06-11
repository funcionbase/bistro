<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\User;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica que active_branch_id del JWT pertenezca a la empresa activa, no esté
 * archivada y el usuario tenga fila en branch_users.
 *
 * Lee: jwt_payload (de ValidateJwt), active_company_nit (de EnsureCompanyAccess).
 * Inyecta: active_branch_id, active_branch (modelo Branch).
 *
 * Convención de respuesta:
 *  - 422 NO_ACTIVE_BRANCH: el token no tiene sede activa (frontend redirige a /select-branch).
 *  - 403 BRANCH_FORBIDDEN: el usuario no tiene acceso a la sede.
 *  - 422 BRANCH_ARCHIVED: la sede fue archivada después de la emisión del token.
 *  - 422 BRANCH_COMPANY_MISMATCH: la sede no pertenece a la empresa activa (defensa contra tampering).
 */
class EnsureBranchAccess
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $payload = $request->attributes->get('jwt_payload');
        $activeBranchId = $payload['active_branch_id'] ?? null;

        if ($activeBranchId === null) {
            return response()->json([
                'message' => 'No has seleccionado una sede activa.',
                'code' => 'NO_ACTIVE_BRANCH',
            ], 422);
        }

        $activeCompanyNit = $request->attributes->get('active_company_nit')
            ?? ($payload['active_company_nit'] ?? null);

        $branch = Branch::query()->find($activeBranchId);

        if ($branch === null) {
            return response()->json([
                'message' => 'Sede no encontrada.',
                'code' => 'BRANCH_NOT_FOUND',
            ], 404);
        }

        if ($branch->company_nit !== $activeCompanyNit) {
            return response()->json([
                'message' => 'La sede no pertenece a la empresa activa.',
                'code' => 'BRANCH_COMPANY_MISMATCH',
            ], 422);
        }

        if ($branch->isArchived()) {
            return response()->json([
                'message' => 'La sede fue archivada.',
                'code' => 'BRANCH_ARCHIVED',
            ], 422);
        }

        $userId = (string) ($payload['sub'] ?? '');
        $isOwner = ($payload['role']['is_system'] ?? false) === true;

        // Owners (rol is_system=true) tienen acceso a todas las sedes de la empresa
        // sin necesidad de fila explícita en branch_users — alineado con cómo
        // FeaturePermissionService los trata (bypass total).
        $hasAccess = $isOwner || BranchUser::query()
            ->where('branch_id', $branch->id)
            ->where('user_id', $userId)
            ->exists();

        if (! $hasAccess) {
            $user = User::find($userId);
            if ($user) {
                $this->auditService->log('branch.unauthorized_access', $user, $branch, [
                    'branch_id' => $branch->id,
                    'company_nit' => $activeCompanyNit,
                    'path' => $request->path(),
                ], $request);
            }

            return response()->json([
                'message' => 'No tienes acceso a esta sede.',
                'code' => 'BRANCH_FORBIDDEN',
            ], 403);
        }

        $request->attributes->set('active_branch_id', $branch->id);
        $request->attributes->set('active_branch', $branch);

        return $next($request);
    }
}

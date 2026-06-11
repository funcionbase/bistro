<?php

namespace App\Http\Controllers\Enrollment;

use App\Http\Controllers\Controller;
use App\Models\CompanyInvitation;
use App\Models\CompanyRole;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Enrollment\EmployeeProvisioningService;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Acepta la invitación pendiente más reciente del usuario autenticado.
 *
 * No requiere body; la invitación se localiza por el email del JWT.
 * Retorna 410 si la invitación está expirada (y la marca como tal).
 * Retorna 422 si el usuario ya es miembro de la empresa de la invitación.
 * Si la invitación no tiene company_role_id, asigna el primer rol no-system de la empresa o el primero disponible.
 * Verifica el email automáticamente si no estaba verificado.
 * Emite un nuevo JWT con enrollment_step='complete'.
 */
class InvitedEnrollmentController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly AuditService $auditService,
        private readonly EmployeeProvisioningService $employeeProvisioning,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt_payload');
        $user = User::findOrFail($payload['sub']);

        $invitation = CompanyInvitation::where('email', $user->email)
            ->latest()
            ->first();

        if ($invitation === null) {
            return response()->json(['message' => 'No se encontró una invitación pendiente para este usuario.'], 404);
        }

        if ($invitation->status === 'expired' || $invitation->isExpired()) {
            if ($invitation->status !== 'expired') {
                $invitation->update(['status' => 'expired']);
            }

            return response()->json(['message' => 'La invitación ha expirado.'], 410);
        }

        if ($invitation->status !== 'pending') {
            return response()->json(['message' => 'No se encontró una invitación pendiente para este usuario.'], 404);
        }

        $alreadyMember = $user->companyMemberships()
            ->where('company_nit', $invitation->company_nit)
            ->exists();

        if ($alreadyMember) {
            return response()->json(['message' => 'Ya eres miembro de esta empresa.'], 422);
        }

        $companyRoleId = $invitation->company_role_id;

        if ($companyRoleId === null) {
            $companyRoleId = CompanyRole::where('company_nit', $invitation->company_nit)
                ->where('is_system', false)
                ->value('id')
                ?? CompanyRole::where('company_nit', $invitation->company_nit)->value('id');
        }

        $user->companyMemberships()->create([
            'company_nit' => $invitation->company_nit,
            'company_role_id' => $companyRoleId,
        ]);

        $invitation->update(['status' => 'accepted']);

        // Verificar email si aún no está verificado
        if (is_null($user->email_verified_at)) {
            $user->email_verified_at = now();
            $user->save();
        }

        // Asegurar que el invitado tenga un perfil `employees` en la
        // empresa. Si el admin lo pre-creó, lo enlazamos por email; si solo
        // mandó la invitación, lo creamos con los datos personales del user.
        // Sin esto, el guard de turno activo en caja le bloquearía operar.
        $this->employeeProvisioning->ensureProfileFor(
            $user,
            $invitation->company_nit,
            'invited_enrollment',
        );

        $this->auditService->log('invitation.accepted', $user, $invitation, [], $request);

        $companyNits = $user->companies()->pluck('companies.nit')->toArray();
        $token = $this->jwtService->issue($user, $companyNits);

        return response()
            ->json([
                'authenticated' => true,
                'enrollment_step' => 'complete',
            ])
            ->withCookie($this->jwtService->buildCookie($token));
    }
}

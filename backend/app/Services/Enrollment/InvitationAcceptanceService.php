<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\CompanyInvitation;
use App\Models\CompanyRole;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Acepta las invitaciones pendientes (no expiradas) de un usuario.
 *
 * Fuente única para las tres rutas que deben comportarse igual:
 *   1. `GoogleAuthController::callback` — usuario YA enrolado que hace login y
 *      trae invitaciones pendientes (el caso que rompía: sin esto la invitación
 *      quedaba `pending` para siempre porque nadie la aceptaba).
 *   2. `UserEnrollmentController::store` — signup nuevo que completa datos y
 *      arrastra invitaciones creadas antes de existir la cuenta.
 *   3. Migración de backfill — acepta lo que quedó colgado en cuentas que ya
 *      existían antes del fix.
 *
 * Idempotente: sólo toca invitaciones `pending`. Marca las expiradas como
 * `expired`. Si el usuario ya es miembro de la empresa, marca la invitación
 * `accepted` sin duplicar la membership.
 */
class InvitationAcceptanceService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly EmployeeProvisioningService $employeeProvisioning,
    ) {}

    /**
     * Procesa todas las invitaciones pendientes al email del usuario.
     *
     * @param  string  $via  Identificador del flujo (audit / provisioning).
     * @return int Cantidad de invitaciones aceptadas (nuevas memberships).
     */
    public function acceptAllPendingFor(User $user, string $via = 'login_auto_accept'): int
    {
        $pending = CompanyInvitation::query()
            ->where('email', $user->email)
            ->where('status', 'pending')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $accepted = 0;

        foreach ($pending as $invitation) {
            if ($this->accept($user, $invitation, $via)) {
                $accepted++;
            }
        }

        if ($accepted > 0 && is_null($user->email_verified_at)) {
            // Recibir + aceptar una invitación en el correo prueba titularidad
            // del email; lo marcamos verificado como hace el flujo del endpoint
            // `/enrollment/invited`.
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $accepted;
    }

    private function accept(User $user, CompanyInvitation $invitation, string $via): bool
    {
        if ($invitation->isExpired()) {
            $invitation->update(['status' => 'expired']);

            return false;
        }

        $companyRoleId = $invitation->company_role_id
            ?? CompanyRole::query()
                ->where('company_nit', $invitation->company_nit)
                ->where('is_system', false)
                ->value('id')
            ?? CompanyRole::query()
                ->where('company_nit', $invitation->company_nit)
                ->value('id');

        if ($companyRoleId === null) {
            Log::warning('InvitationAcceptanceService: empresa sin roles, saltando invitación', [
                'invitation_id' => $invitation->id,
                'company_nit' => $invitation->company_nit,
            ]);

            return false;
        }

        $alreadyMember = $user->companyMemberships()
            ->where('company_nit', $invitation->company_nit)
            ->exists();

        DB::transaction(function () use ($user, $invitation, $companyRoleId, $alreadyMember, $via) {
            if (! $alreadyMember) {
                $user->companyMemberships()->create([
                    'company_nit' => $invitation->company_nit,
                    'company_role_id' => $companyRoleId,
                ]);
            }

            $invitation->update(['status' => 'accepted']);

            $this->auditService->log('invitation.accepted', $user, $invitation, [
                'company_nit' => $invitation->company_nit,
                'company_role_id' => $companyRoleId,
                'already_member' => $alreadyMember,
                'via' => $via,
            ]);
        });

        // Sin perfil `employees` el guard de turno activo en caja bloquea al
        // invitado aunque tenga la membership + permisos. Idempotente: linkea
        // por email o crea con la primera sede operable.
        $this->employeeProvisioning->ensureProfileFor(
            $user,
            $invitation->company_nit,
            $via,
        );

        return ! $alreadyMember;
    }
}

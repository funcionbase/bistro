<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditService;

/**
 * Provisiona el perfil de colaborador (`employees`) de un usuario en una
 * empresa específica. Idempotente: si ya existe employee con email matching
 * en la empresa, solo enlaza `user_id` cuando falta — no duplica filas ni
 * pisa datos HHRR cargados por el admin.
 *
 * Se invoca desde:
 *   - InvitedEnrollmentController::store (acepta invitación post-enrollment).
 *   - UserEnrollmentController::store (completa datos personales en signup
 *     nuevo cuando ya había invitación pendiente).
 *
 * Sin este perfil, el guard de turno activo en caja bloquea al invitado
 * aunque tenga membership + permisos correctos.
 */
class EmployeeProvisioningService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Garantiza que `$user` tenga perfil `employees` en `$companyNit`.
     *
     * Estrategia:
     *   1. Si existe employee con mismo email en la empresa pero sin user_id,
     *      linkear (matched_by_email).
     *   2. Si existe employee ya linkeado al user, no-op.
     *   3. Si no existe, crearlo con datos personales del user + primera sede
     *      operable de la empresa (preferencia: default, luego alfabético).
     *   4. Si la empresa no tiene sedes operables, abortar silenciosamente
     *      (el admin tendrá que crear el employee manualmente más tarde).
     *
     * @param  string  $via  Identificador del flujo que invocó (audit_log).
     *                       Valores estandarizados: `invited_enrollment`,
     *                       `user_enrollment_with_invitation`.
     */
    public function ensureProfileFor(User $user, string $companyNit, string $via): void
    {
        $existing = Employee::query()
            ->where('company_nit', $companyNit)
            ->where('email', $user->email)
            ->first();

        if ($existing !== null) {
            if ($existing->user_id === null) {
                $existing->update(['user_id' => $user->id]);
                $this->auditService->log('employee.linked_to_user', $user, $existing, [
                    'matched_by_email' => $user->email,
                    'via' => $via,
                ]);
            }

            return;
        }

        $branch = Branch::query()
            ->where('company_nit', $companyNit)
            ->whereNull('archived_at')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();

        if ($branch === null) {
            // Sin sedes operables: imposible crear employee (primary_branch_id
            // es NOT NULL). El admin tendrá que crear el perfil manualmente
            // luego de crear la primera sede. No fallamos el enrollment.
            return;
        }

        $employee = Employee::create([
            'company_nit' => $companyNit,
            'user_id' => $user->id,
            'primary_branch_id' => $branch->id,
            'doc_type' => 'CC',
            'doc_number' => $user->cedula ?? 'PENDIENTE-'.$user->id,
            'first_name' => $user->first_name ?? $user->name,
            'last_name' => $user->last_name ?? '',
            'email' => $user->email,
            'pay_type' => 'mensual',
            'pay_rate' => 0,
            'contract_type' => 'fijo',
            'hire_date' => now()->toDateString(),
            'vinculation_status' => 'active',
        ]);

        $this->auditService->log('employee.created', $user, $employee, [
            'via' => $via,
            'note' => 'Autocreado al aceptar invitación; admin debe ajustar pay_rate.',
        ]);
    }
}

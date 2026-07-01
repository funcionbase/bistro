<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Enrollment\InvitationAcceptanceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: acepta las invitaciones que quedaron `pending` en cuentas que YA
 * existían (enroladas) antes del fix de auto-accept en login.
 *
 * Hasta este fix, invitar a un usuario ya registrado dejaba la invitación
 * `pending` para siempre: el auto-accept sólo corría en el signup nuevo. Estas
 * cuentas no deben esperar a re-loguear para desbloquearse.
 *
 * Sólo toca usuarios enrolados (status != 'pending_enrollment'): los que aún no
 * completaron enrollment aceptarán sus invitaciones al terminarlo, con TOS y
 * datos personales de por medio.
 *
 * Idempotente: reutiliza InvitationAcceptanceService (sólo procesa `pending`,
 * no duplica memberships, marca expiradas).
 */
return new class extends Migration
{
    public function up(): void
    {
        $service = app(InvitationAcceptanceService::class);

        $emails = DB::table('company_invitations')
            ->where('status', 'pending')
            ->distinct()
            ->pluck('email');

        User::query()
            ->whereIn('email', $emails)
            ->where('status', '!=', 'pending_enrollment')
            ->cursor()
            ->each(fn (User $user) => $service->acceptAllPendingFor($user, 'backfill_existing_users'));
    }

    public function down(): void
    {
        // ponytail: no rollback — aceptar una invitación es una acción de
        // negocio, no un cambio de schema. Revertir memberships a mano si hiciera
        // falta (identificables por audit_log action=invitation.accepted via=backfill_existing_users).
    }
};

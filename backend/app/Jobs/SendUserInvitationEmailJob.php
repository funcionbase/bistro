<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Notifications\UserInvitedNotification;
use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Correo transaccional de "fuiste invitado a una empresa".
 *
 * Job orquestador del envío. Garantiza idempotencia N-instance safe
 * con las mismas 4 capas que `SendCompanyRegistrationWelcomeEmailJob`:
 *
 *   1. `SELECT ... FOR UPDATE SKIP LOCKED` del driver `database` de la cola
 *      — un solo worker procesa cada fila de `jobs`.
 *   2. `ShouldBeUnique` con `uniqueId="invitation_email:{invitation_id}"` y
 *      `uniqueFor=3600` — bloquea encolado duplicado por 1 h vía cache store
 *      `database` (compartido entre EC2 vía PostgreSQL). El TTL es más corto
 *      que welcome_email porque el operador SÍ puede reenviar manualmente
 *      una invitación que falló (por eso 1 h, no 24 h).
 *   3. `company_invitations.email_sent_at` consultada antes de enviar dentro
 *      de `DB::transaction` con `lockForUpdate`, actualizada al terminar OK.
 *   4. `after_commit: true` global en `config/queue.php` — el job no se encola
 *      si la transacción de `InvitationController::store` revierte.
 *
 * Sin ShouldBeUnique, dos requests concurrentes del frontend (doble-tap del
 * botón "invitar") o un reencolado manual desde `failed_jobs` podrían
 * disparar dos correos al mismo invitado. Con las 4 capas, el envío es
 * exactamente una vez por invitación.
 *
 * Errores: cualquier excepción en `handle()` deja el job en `failed_jobs`
 * tras agotar los reintentos. `failed()` loggea `invitation.email_failed`
 * para que el operador investigue sin afectar la creación de la invitación
 * (que ya commiteó en `InvitationController::store()`). El operador puede
 * reenviar via endpoint dedicado (futuro) o re-encolando manualmente.
 */
class SendUserInvitationEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Vida del lock de unicidad — 1 h. Más corto que welcome_email porque el
     * operador puede legítimamente reenviar una invitación que falló (p.ej.
     * el invitado borró el correo). Tras 1 h sin éxito, ya pueden reencolar.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $invitationId,
        public readonly ?string $invitedByUserId = null,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Identidad para el lock de unicidad (capa 2). El cache store es
     * `database` (config/cache.php), compartido entre todas las EC2 del ASG.
     */
    public function uniqueId(): string
    {
        return "invitation_email:{$this->invitationId}";
    }

    public function handle(AuditService $audit): void
    {
        DB::transaction(function () use ($audit) {
            $invitation = CompanyInvitation::query()
                ->whereKey($this->invitationId)
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof CompanyInvitation) {
                Log::warning('SendUserInvitationEmailJob: invitación no encontrada', [
                    'invitation_id' => $this->invitationId,
                ]);

                return;
            }

            if ($invitation->email_sent_at !== null) {
                Log::info('SendUserInvitationEmailJob: correo ya enviado, omitiendo', [
                    'invitation_id' => $this->invitationId,
                    'sent_at' => $invitation->email_sent_at->toIso8601String(),
                ]);

                return;
            }

            if ($invitation->status !== 'pending' || $invitation->isExpired()) {
                Log::info('SendUserInvitationEmailJob: invitación no pendiente o expirada, omitiendo', [
                    'invitation_id' => $this->invitationId,
                    'status' => $invitation->status,
                    'expires_at' => $invitation->expires_at?->toIso8601String(),
                ]);

                return;
            }

            // Company PK ahora es id uuid; el FK sigue siendo company_nit -> companies.nit.
            // Lookup explícito por nit (NO whereKey, que busca por id uuid).
            $company = Company::query()->where('nit', $invitation->company_nit)->first();
            if (! $company instanceof Company) {
                Log::warning('SendUserInvitationEmailJob: empresa no encontrada', [
                    'invitation_id' => $this->invitationId,
                    'company_nit' => $invitation->company_nit,
                ]);

                return;
            }

            $invitedBy = $this->invitedByUserId !== null
                ? User::query()->find($this->invitedByUserId)
                : null;

            // At-most-once delivery: marcamos email_sent_at ANTES del send
            // (commit dentro del lockForUpdate). Si el SMTP falla, el correo
            // queda como "enviado" — preferimos perder un mensaje (operador
            // re-encola con "reenviar") sobre disparar 3 copias por reintento
            // automático tras un crash a mitad del send. La columna se vuelve
            // candado contra dobles intents del mismo workflow_run.
            $invitation->forceFill(['email_sent_at' => now()])->save();

            Notification::route('mail', $invitation->email)
                ->notifyNow(new UserInvitedNotification($invitation, $company, $invitedBy));

            $audit->log(
                'invitation.email_sent',
                user: $invitedBy,
                auditable: $invitation,
                data: [
                    'company_nit' => $invitation->company_nit,
                    'invited_email' => $invitation->email,
                    'expires_at' => $invitation->expires_at?->toIso8601String(),
                ],
            );
        });
    }

    public function failed(Throwable $e): void
    {
        $audit = app(AuditService::class);
        $invitation = CompanyInvitation::query()->find($this->invitationId);
        $invitedBy = $this->invitedByUserId !== null
            ? User::query()->find($this->invitedByUserId)
            : null;

        $audit->log(
            'invitation.email_failed',
            user: $invitedBy,
            auditable: $invitation,
            data: [
                'invitation_id' => $this->invitationId,
                'reason' => $e->getMessage(),
                'exception' => $e::class,
            ],
        );
    }
}

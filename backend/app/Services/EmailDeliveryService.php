<?php

namespace App\Services;

use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Política de envío de correo y gestión de la suppression list.
 *
 * - `isSuppressed($email)`: el listener {@see \App\Listeners\AbortIfSuppressed}
 *   lo consulta antes de cada envío. Bloquea hard bounces, complaints y
 *   suppressions manuales.
 * - `suppress(...)`: registra una nueva supresión. Idempotente — si ya existe
 *   una suppression activa para ese (email, reason), no crea duplicado.
 * - `unsuppress(...)`: marca como expirada (no la borra, audit trail).
 *
 * NO se hace SES API call directo aquí — la fuente de suppression es nuestra
 * tabla local, que se mantiene sincronizada con SES via el webhook SNS.
 * Esto evita latencia y AWS API limits en el path crítico de envío.
 */
class EmailDeliveryService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * ¿La dirección está suprimida y NO se debe enviar correo?
     *
     * Performance: índice parcial `email_suppressions_lookup_idx` sobre
     * `LOWER(email)` cuando `expires_at IS NULL`. Una consulta por envío.
     */
    public function isSuppressed(string $email): bool
    {
        $email = trim($email);

        if ($email === '') {
            return false;
        }

        return EmailSuppression::query()
            ->active()
            ->forEmail($email)
            ->exists();
    }

    /**
     * Registra una supresión. Idempotente: si ya hay activa para
     * `(email, reason)`, retorna la existente sin duplicar.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function suppress(
        string $email,
        string $reason,
        ?string $subtype = null,
        array $metadata = [],
        ?\DateTimeInterface $receivedAt = null,
        ?\DateTimeInterface $expiresAt = null,
        ?User $createdBy = null,
    ): EmailSuppression {
        if (! in_array($reason, EmailSuppression::REASONS, true)) {
            throw new \InvalidArgumentException(
                "Invalid suppression reason: {$reason}. Allowed: ".implode(', ', EmailSuppression::REASONS)
            );
        }

        $email = mb_strtolower(trim($email));

        // Idempotencia: la unique index parcial lanza QueryException si
        // hay conflicto. Capturamos y retornamos la existente.
        try {
            $suppression = EmailSuppression::create([
                'email' => $email,
                'reason' => $reason,
                'subtype' => $subtype,
                'metadata' => $metadata,
                'received_at' => $receivedAt ?? now(),
                'expires_at' => $expiresAt,
                'created_by_user_id' => $createdBy?->id,
            ]);

            $this->auditService->log(
                action: 'email.suppressed',
                user: $createdBy,
                auditable: $suppression,
                data: [
                    'email' => $email,
                    'reason' => $reason,
                    'subtype' => $subtype,
                    'expires_at' => $expiresAt?->format('c'),
                ],
            );

            return $suppression;
        } catch (QueryException $e) {
            // Race condition: otro hilo creó la misma suppression entre el
            // check y el insert. La unique partial index garantiza una sola
            // suppression activa por (email, reason) — devolvemos la existente.
            if (! str_contains($e->getMessage(), 'email_suppressions_email_reason_active_unique')) {
                throw $e;
            }

            Log::channel('single')->info('email.suppression.duplicate_race', [
                'email' => $email,
                'reason' => $reason,
            ]);

            return EmailSuppression::query()
                ->active()
                ->forEmail($email)
                ->where('reason', $reason)
                ->firstOrFail();
        }
    }

    /**
     * Marca como expirada (no borra). El registro queda para audit trail.
     * Útil para soft bounces transitorios o cuando el usuario solicita
     * reactivar (con doble confirmación por reply al correo).
     */
    public function unsuppress(EmailSuppression $suppression, ?User $actor = null): void
    {
        if ($suppression->expires_at !== null) {
            return; // Ya expirada — noop.
        }

        $suppression->forceFill(['expires_at' => now()])->save();

        $this->auditService->log(
            action: 'email.unsuppressed',
            user: $actor,
            auditable: $suppression,
            data: [
                'email' => $suppression->email,
                'reason' => $suppression->reason,
            ],
        );
    }
}

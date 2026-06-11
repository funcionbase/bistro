<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Exceptions\Account\AccountEmailChangeException;
use App\Models\AccountEmailChangeRequest;
use App\Models\User;
use App\Notifications\AccountEmailChangeRequestedNotification;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recuperación de cuenta por cambio de correo manteniendo la cédula única.
 *
 * Caso: una persona entra con un Google nuevo (→ user huérfano vacío) e intenta
 * enrolarse con una cédula que ya pertenece a otra cuenta. En vez de un
 * dead-end, se ofrece mover la cuenta existente al correo nuevo. La prueba de
 * identidad es el control del correo VIEJO: el enlace de confirmación se envía
 * ahí, nunca se confía en la sola cédula (no es secreta).
 *
 * Seguridad:
 *  - Token crudo solo viaja en el email; en BD vive su SHA-256.
 *  - Un solo uso (`used_at`) + expira (`expires_at`, 1h).
 *  - El huérfano solo se borra si está vacío (sin empresas) — defensa contra
 *    mover datos por error.
 */
class AccountEmailChangeService
{
    private const TTL_MINUTES = 60;

    public function __construct(private readonly AuditService $auditService) {}

    /**
     * Crea una solicitud de cambio y envía el enlace de confirmación al correo
     * actual (viejo) de la cuenta dueña de la cédula. Devuelve el token crudo
     * (solo para tests/uso interno; en producción viaja únicamente por email).
     */
    public function request(User $targetUser, User $orphan): string
    {
        $rawToken = Str::random(64);
        $tokenHash = hash('sha256', $rawToken);

        DB::transaction(function () use ($targetUser, $orphan, $tokenHash): void {
            // Invalida solicitudes previas pendientes hacia la misma cuenta para
            // que solo el último enlace quede vigente.
            AccountEmailChangeRequest::query()
                ->where('target_user_id', $targetUser->id)
                ->whereNull('used_at')
                ->delete();

            AccountEmailChangeRequest::query()->create([
                'target_user_id' => $targetUser->id,
                'requested_by_user_id' => $orphan->id,
                'new_email' => $orphan->email,
                'new_google_id' => $orphan->google_id,
                'token_hash' => $tokenHash,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);
        });

        $confirmUrl = route('auth.email-change.confirm', ['token' => $rawToken]);

        // Notifiable = targetUser ⇒ el correo va a su email ACTUAL (el viejo).
        $targetUser->notify(new AccountEmailChangeRequestedNotification(
            confirmUrl: $confirmUrl,
            newEmail: $orphan->email,
            expiresInMinutes: self::TTL_MINUTES,
        ));

        return $rawToken;
    }

    /**
     * Resuelve una solicitud pendiente por token crudo, sin mutar nada (para la
     * pantalla de confirmación GET). Null si no existe / expiró / ya se usó.
     */
    public function findPending(string $rawToken): ?AccountEmailChangeRequest
    {
        $request = AccountEmailChangeRequest::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        return $request !== null && $request->isPending() ? $request : null;
    }

    /**
     * Ejecuta el cambio: mueve email + google_id de la cuenta existente al
     * correo nuevo y borra el huérfano. Idempotencia y concurrencia con
     * lockForUpdate. Devuelve la cuenta movida.
     *
     * @throws AccountEmailChangeException
     */
    public function confirm(string $rawToken): User
    {
        $tokenHash = hash('sha256', $rawToken);

        return DB::transaction(function () use ($tokenHash): User {
            $request = AccountEmailChangeRequest::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($request === null || ! $request->isPending()) {
                throw new AccountEmailChangeException('El enlace es inválido o expiró. Solicítalo de nuevo.');
            }

            /** @var User $target */
            $target = User::query()->whereKey($request->target_user_id)->lockForUpdate()->firstOrFail();
            $oldEmail = $target->email;
            $newEmail = $request->new_email;

            // Libera el correo/google nuevos borrando el huérfano. Solo si está
            // vacío (sin empresas): nunca destruimos una cuenta con datos.
            if ($request->requested_by_user_id !== null && $request->requested_by_user_id !== $target->id) {
                $orphan = User::query()->whereKey($request->requested_by_user_id)->lockForUpdate()->first();

                if ($orphan !== null) {
                    if ($orphan->companyUsers()->exists()) {
                        throw new AccountEmailChangeException('La cuenta nueva ya tiene datos asociados; no se puede completar el cambio automáticamente. Contacta a soporte.');
                    }
                    $orphan->delete();
                }
            }

            $target->forceFill([
                'email' => $newEmail,
                'google_id' => $request->new_google_id,
            ])->save();

            $request->forceFill(['used_at' => now()])->save();

            $this->auditService->log('user.email_changed', $target, $target, [
                'old_email' => $oldEmail,
                'new_email' => $newEmail,
                'via' => 'cedula_recovery',
            ]);

            return $target;
        });
    }
}

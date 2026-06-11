<?php

namespace App\Policies;

use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\User;

/**
 * Policy de cambio de estado de vinculación de un colaborador (#182 §12).
 *
 * Las 4 reglas duras:
 *  1. Un usuario no puede cambiar su propio estado.
 *  2. Un propietario (owner) es indesactivable mientras sea owner.
 *  3. Un administrador no puede desactivar a un propietario.
 *  4. Los owners pueden desactivar a cualquiera salvo a sí mismos y a otros owners.
 *
 * `canChangeState` retorna el motivo del bloqueo o `null` si la transición
 * está permitida. El controller decide el código HTTP (siempre 403) y el
 * mensaje al cliente, además de loguear `employee.vinculation_change_denied`
 * con el motivo para detectar abuso.
 */
class EmployeeVinculationPolicy
{
    public const REASON_SELF = 'self';

    public const REASON_TARGET_IS_OWNER = 'target_is_owner';

    public const REASON_ADMIN_CANNOT_DEMOTE_OWNER = 'admin_cannot_demote_owner';

    /**
     * Verifica si el actor puede cambiar el estado de vinculación del target.
     *
     * @return ?string Motivo del bloqueo (constante REASON_*) o null si permitido.
     */
    public function denialReason(User $actor, Employee $target, string $newStatus): ?string
    {
        // users.id es uuid: NUNCA castear a (int) — devuelve PHP_INT_MAX
        // silenciosamente y rompe TODAS las comparaciones (siempre true) +
        // la lookup en CompanyUser por user_id devuelve vacío (siempre
        // "no es owner"). Comparar siempre como string.
        $actorId = (string) $actor->id;
        $targetUserId = $target->user_id !== null ? (string) $target->user_id : null;

        // Regla 1: auto-desactivación prohibida. Aplica solo si el nuevo estado
        // no es 'active' (no tiene sentido bloquear cuando ya estaba inactive).
        if ($targetUserId !== null && $targetUserId === $actorId && $newStatus !== 'active') {
            return self::REASON_SELF;
        }

        $targetIsOwner = $targetUserId !== null
            ? $this->userIsOwnerInCompany($targetUserId, $target->company_nit)
            : false;

        // Regla 2: owner es indesactivable. Aplica solo si el nuevo estado
        // no es 'active'. Cualquier intento queda bloqueado, incluso desde
        // otro owner. Para desactivarlo hay que quitarle primero el rol.
        if ($targetIsOwner && $newStatus !== 'active') {
            return self::REASON_TARGET_IS_OWNER;
        }

        // Regla 3: admin no puede tocar owners (cualquier transición).
        // Si el target es owner y el actor no es owner, blocked.
        $actorIsOwner = $this->userIsOwnerInCompany($actorId, $target->company_nit);

        if ($targetIsOwner && ! $actorIsOwner) {
            return self::REASON_ADMIN_CANNOT_DEMOTE_OWNER;
        }

        return null;
    }

    public function canChangeState(User $actor, Employee $target, string $newStatus): bool
    {
        return $this->denialReason($actor, $target, $newStatus) === null;
    }

    /**
     * True si el usuario tiene un rol del sistema (is_system=true) y su nombre
     * coincide con el nombre canónico del owner. Centralizamos la detección
     * acá para no acoplarnos al string "Propietario" en varios sitios.
     */
    private function userIsOwnerInCompany(string $userId, string $companyNit): bool
    {
        $ownerRoleName = config('roles.role_names.owner', 'Propietario');

        return CompanyUser::query()
            ->where('user_id', $userId)
            ->where('company_nit', $companyNit)
            ->whereHas('role', fn ($q) => $q->where('is_system', true)->where('name', $ownerRoleName))
            ->exists();
    }
}

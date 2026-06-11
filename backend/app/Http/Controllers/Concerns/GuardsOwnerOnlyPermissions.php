<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Feature;

/**
 * Garantiza el invariante de `features.is_owner_only`: una feature owner-only
 * NO es asignable a un rol no-sistema. El editor de permisos las deshabilita en
 * el frontend; este trait es la defensa en backend para que un cliente
 * malicioso/buggy no pueda otorgarlas vía API.
 *
 * Lo usan RoleController y UserRoleController (ambos editan roles no-sistema).
 */
trait GuardsOwnerOnlyPermissions
{
    /**
     * Fuerza a `false` los 4 bits CRUD de toda feature owner-only en el payload
     * de permisos. No lanza error: silenciosamente neutraliza el grant para no
     * romper el resto del guardado (el cliente bien comportado nunca los envía).
     *
     * @param  array<int, array<string, mixed>>  $permissions
     * @return array<int, array<string, mixed>>
     */
    protected function stripOwnerOnlyGrants(array $permissions): array
    {
        $ownerOnlyIds = Feature::query()->where('is_owner_only', true)->pluck('id')->all();

        if ($ownerOnlyIds === []) {
            return $permissions;
        }

        return array_map(function (array $permission) use ($ownerOnlyIds): array {
            if (in_array($permission['feature_id'] ?? null, $ownerOnlyIds, true)) {
                $permission['can_create'] = false;
                $permission['can_read'] = false;
                $permission['can_update'] = false;
                $permission['can_delete'] = false;
            }

            return $permission;
        }, $permissions);
    }
}

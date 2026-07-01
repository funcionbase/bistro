<?php

declare(strict_types=1);

use App\Models\CompanyRole;
use App\Models\CompanyRolePermission;
use App\Models\Feature;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill: otorga `orders.create` (can_create + can_read) a los roles Cajero
 * existentes.
 *
 * El template del Cajero no incluía `orders.create`, así que el rol no podía
 * abrir caja (cash-register/open exige `orders.create,create`) ni veía el ítem
 * "Caja" en el sidebar (gate `permission: 'orders.create'`) — justo su función
 * principal.
 *
 * Aditivo y quirúrgico: sólo toca la fila `orders.create` de roles con el nombre
 * canónico Cajero (is_system=false). No crea roles ni reconcilia el resto de
 * permisos (respeta ediciones del owner). Usa el modelo Eloquent para disparar
 * CompanyRolePermissionObserver e invalidar la caché de permisos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cashierName = config('roles.role_names.cashier');
        $featureId = Feature::query()->where('slug', 'orders.create')->value('id');

        if (! is_string($cashierName) || $featureId === null) {
            return;
        }

        CompanyRole::query()
            ->where('name', $cashierName)
            ->where('is_system', false)
            ->cursor()
            ->each(function (CompanyRole $role) use ($featureId) {
                CompanyRolePermission::updateOrCreate(
                    ['company_role_id' => $role->id, 'feature_id' => $featureId],
                    ['can_create' => true, 'can_read' => true],
                );
            });
    }

    public function down(): void
    {
        // ponytail: no rollback — permiso aditivo. Revertir a mano si hiciera
        // falta (can_create/can_read=false en orders.create de roles Cajero).
    }
};

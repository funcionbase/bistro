<?php

declare(strict_types=1);

use Database\Seeders\PermissionTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Propaga el nuevo rol operativo "Domiciliario" (role_type=courier) a las
 * empresas existentes de pdn.
 *
 * El seeder (fuente de verdad) no corre en pdn, y las empresas ya creadas no
 * reciben el rol nuevo automáticamente. Reutilizamos las herramientas de
 * producción en vez de duplicar la lógica:
 *   1. PermissionTemplateSeeder — upsert idempotente de los permission_templates
 *      (incluye la fila role_type=courier recién agregada).
 *   2. roles:sync-templates --role=courier — crea el rol "Domiciliario"
 *      (is_system=false) en cada empresa que no lo tenga y reconcilia permisos.
 *
 * Ambos son idempotentes; re-ejecutar no duplica ni pisa ediciones de nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('db:seed', [
            '--class' => PermissionTemplateSeeder::class,
            '--force' => true,
        ]);

        Artisan::call('roles:sync-templates', ['--role' => 'courier']);
    }

    public function down(): void
    {
        // ponytail: no rollback — el rol es renombrable/eliminable por el owner
        // desde /identities/roles. Borrarlo masivamente arriesga romper
        // memberships asignadas manualmente después del backfill.
    }
};

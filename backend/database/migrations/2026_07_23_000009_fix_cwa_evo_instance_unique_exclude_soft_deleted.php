<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: `cwa_evo_instance_unique` debe excluir soft-deleted.
 *
 * La migración `..._000001_extend_whatsapp_accounts_channels` (§5.2) creó
 * `cwa_evo_instance_unique` SIN `AND deleted_at IS NULL`, a diferencia de los
 * otros índices parciales de la tabla (`cwa_company_scope_unique`,
 * `cwa_branch_scope_unique`), que sí lo excluyen.
 *
 * Efecto del bug: un canal **soft-deleted** (un intento de conexión fallido que
 * quedó purgado) sigue ocupando el slot único de su `evo_instance`. Al
 * re-provisionar el MISMO canal, el INSERT choca contra el índice → 23505
 * (duplicate key) → 500 en el wizard "Conectar" (verificado en pdn: un canal
 * pending soft-deleted bloqueaba re-vincular la misma sede).
 *
 * Se recrea el índice excluyendo soft-deleted, para que la purga libere el slot.
 * Aditiva y reversible: sólo dropea y recrea el índice; no toca datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS cwa_evo_instance_unique');
        DB::statement('CREATE UNIQUE INDEX cwa_evo_instance_unique ON company_whatsapp_accounts (evo_instance)
                       WHERE evo_instance IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cwa_evo_instance_unique');
        DB::statement('CREATE UNIQUE INDEX cwa_evo_instance_unique ON company_whatsapp_accounts (evo_instance)
                       WHERE evo_instance IS NOT NULL');
    }
};

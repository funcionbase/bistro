<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Multi-caja por sede (Fase 1): la unicidad de "una sesión abierta" pasa de
 * POR SEDE a POR CAJA.
 *
 * Corre DESPUÉS del backfill (orden de archivos: "backfill" < "swap"), cuando
 * toda sesión existente ya tiene `cash_register_id`. A partir de aquí una sede
 * puede tener N sesiones abiertas (una por caja); cada caja, máximo una.
 *
 * El índice parcial de Postgres ignora NULLs, así que sesiones legacy sin caja
 * (no debería quedar ninguna tras el backfill) no romperían el swap.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_cash_session_one_open_per_branch');
        DB::statement("CREATE UNIQUE INDEX idx_cash_session_one_open_per_register
            ON cash_register_sessions (cash_register_id)
            WHERE status = 'open'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_cash_session_one_open_per_register');
        DB::statement("CREATE UNIQUE INDEX idx_cash_session_one_open_per_branch
            ON cash_register_sessions (company_nit, branch_id)
            WHERE status = 'open'");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convierte `users.name` en una columna GENERADA (stored) derivada de
 * first_name + last_name. A partir de aquí `name` nunca se escribe directo:
 * es siempre `first_name || ' ' || last_name` (null-safe).
 *
 * Solo aplica en PostgreSQL (entornos reales). En SQLite (tests) la columna
 * ya nace generada desde la migración fundacional y no se puede convertir vía
 * ALTER TABLE, así que esta migración es no-op. Idempotente: si `name` ya es
 * generada, no hace nada.
 *
 * Los datos previos de `name` se pueden perder porque son los mismos de
 * first_name/last_name; aun así backfileamos first/last desde `name` cuando
 * estén vacíos (cubre usuarios pending_enrollment creados con el nombre de
 * Google antes de tener first/last).
 */
return new class extends Migration
{
    private const GENERATED_EXPR = "TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, ''))";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if ($this->nameIsGenerated()) {
            return;
        }

        // Backfill: rellena first/last desde `name` solo donde falten, partiendo
        // por el primer espacio. No pisa datos ya cargados en enrollment.
        DB::statement(<<<'SQL'
            UPDATE users
            SET
                first_name = COALESCE(NULLIF(first_name, ''), split_part(name, ' ', 1)),
                last_name = COALESCE(
                    NULLIF(last_name, ''),
                    NULLIF(TRIM(SUBSTR(name, LENGTH(split_part(name, ' ', 1)) + 2)), '')
                )
            WHERE name IS NOT NULL AND name <> ''
        SQL);

        DB::statement('ALTER TABLE users DROP COLUMN name');
        DB::statement(
            'ALTER TABLE users ADD COLUMN name VARCHAR(255) '
            .'GENERATED ALWAYS AS ('.self::GENERATED_EXPR.') STORED'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! $this->nameIsGenerated()) {
            return;
        }

        DB::statement('ALTER TABLE users DROP COLUMN name');
        DB::statement("ALTER TABLE users ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT ''");
        DB::statement('UPDATE users SET name = '.self::GENERATED_EXPR);
        DB::statement('ALTER TABLE users ALTER COLUMN name DROP DEFAULT');
    }

    private function nameIsGenerated(): bool
    {
        $row = DB::selectOne(
            "SELECT is_generated FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'name'"
        );

        return $row !== null && ($row->is_generated ?? null) === 'ALWAYS';
    }
};

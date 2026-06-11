<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Provee `uuidv7()` como función SQL en Postgres < 18 (Supabase corre PG 17.6
 * actualmente). PG 18 trae `uuidv7()` nativo; mientras no migremos, esta
 * función rellena el hueco para inserts raw que NO pasan por Eloquent
 * (workflows GitHub Actions company-ops, jobs de mantenimiento que usen
 * INSERT INTO ... VALUES (...) sin id).
 *
 * Regla del proyecto: las PKs de todas las tablas son UUID v7 — sin
 * excepciones. Modelos Eloquent lo logran con el trait HasUuids (Laravel 12);
 * inserts raw lo logran llamando a esta función como default o como argumento
 * explícito en VALUES.
 *
 * Estructura UUID v7 (RFC 9562):
 *   - 48 bits: timestamp Unix en milisegundos (big-endian).
 *   - 4 bits: versión (0111 = v7).
 *   - 12 bits: random.
 *   - 2 bits: variant (10).
 *   - 62 bits: random.
 *
 * `clock_timestamp()` (no `now()`) garantiza monotonicidad real dentro de una
 * transacción larga — `now()` queda fijo al inicio de la transacción y
 * generaría UUIDs con el mismo prefijo de tiempo aunque transcurran segundos.
 *
 * `IMMUTABLE` no aplica porque depende del reloj. Se marca `VOLATILE` para
 * que el planner no la cachee dentro de una query (cada llamada genera uno
 * distinto).
 *
 * Cuando subamos a PG 18, esta migración se puede tirar abajo: el `uuidv7()`
 * nativo va a tomar precedencia (mismo nombre, signature compatible).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // pgcrypto provee gen_random_bytes(). Supabase ya la tiene activa,
        // pero CREATE EXTENSION IF NOT EXISTS es idempotente.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION uuidv7() RETURNS uuid AS $$
            DECLARE
                unix_ts_ms bytea;
                uuid_bytes bytea;
            BEGIN
                unix_ts_ms := substring(
                    int8send((extract(epoch FROM clock_timestamp()) * 1000)::bigint)
                    FROM 3
                );
                uuid_bytes := unix_ts_ms || gen_random_bytes(10);
                -- byte 6 (0-indexed): version nibble 0111 en los 4 bits superiores.
                uuid_bytes := set_byte(
                    uuid_bytes,
                    6,
                    (
                        (get_byte(uuid_bytes, 6) & 15)  -- preserva los 4 bits inferiores
                        | 112                            -- 0b01110000 = version 7
                    )
                );
                -- byte 8 (0-indexed): variant 10 en los 2 bits superiores.
                uuid_bytes := set_byte(
                    uuid_bytes,
                    8,
                    (
                        (get_byte(uuid_bytes, 8) & 63)  -- preserva los 6 bits inferiores
                        | 128                            -- 0b10000000 = variant RFC 4122
                    )
                );
                RETURN encode(uuid_bytes, 'hex')::uuid;
            END;
            $$ LANGUAGE plpgsql VOLATILE;
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP FUNCTION IF EXISTS uuidv7()');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optimización de table_session_guests.device_token.
 *
 * El lookup público "encontrame la sesión activa del comensal a partir de su
 * cookie httpOnly" se hace por `device_token` solo, sin saber el
 * `table_session_id` (lo resolvemos *desde* el guest):
 *
 *   TableSessionGuest::where('device_token', $deviceToken)
 *       ->whereHas('session', ...active...)
 *       ->with('session')->first();
 *
 * Antes el único índice que cubría `device_token` era
 * `tsg_session_device_unique (table_session_id, device_token) UNIQUE`. PostgreSQL
 * solo usa índices compuestos cuando el predicado incluye el prefijo de la
 * llave; al filtrar solo por `device_token` caía a Seq Scan, lo que crece
 * lineal con el tráfico (cada request del menú público / carrito / KDS pega
 * acá).
 *
 * Agregamos un índice no-único independiente sobre `device_token` para hacer
 * el lookup O(log n) en producción.
 *
 * Mantenemos el UNIQUE compuesto: garantiza la invariante de "un mismo token
 * no se reasigna dentro de la misma sesión" (que el de aquí no garantiza por
 * ser no-único). Postgres aprovecha ambos según el plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_session_guests', function (Blueprint $table) {
            $table->index('device_token', 'table_session_guests_device_token_idx');
        });
    }

    public function down(): void
    {
        Schema::table('table_session_guests', function (Blueprint $table) {
            $table->dropIndex('table_session_guests_device_token_idx');
        });
    }
};

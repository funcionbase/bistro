<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `status_change_reason` informativo en `deliveries`. Cubre los
 * casos del flujo "Mis entregas":
 *
 *  - `error_usuario`: el domiciliario marcó completed por error y revirtió.
 *  - `pedido_rechazado`: el cliente rechazó la entrega al recibirla.
 *
 * La historia completa de transiciones vive en `delivery_status_logs`
 * (append-only). Esta columna solo refleja el ÚLTIMO cambio para mostrarlo
 * inline en la card sin un JOIN.
 *
 * Validamos a nivel BD que el valor esté en la lista cerrada — los enums
 * PostgreSQL son caros de migrar, así que usamos un CHECK constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('status_change_reason', 32)->nullable()->after('cancellation_reason');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE deliveries
            ADD CONSTRAINT deliveries_status_change_reason_check
            CHECK (status_change_reason IS NULL OR status_change_reason IN ('error_usuario', 'pedido_rechazado'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE deliveries DROP CONSTRAINT IF EXISTS deliveries_status_change_reason_check');

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('status_change_reason');
        });
    }
};

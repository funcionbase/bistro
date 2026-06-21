<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-caja por sede — vincula cada turno a una caja (Fase 1).
 *
 * Columna NULLABLE en esta migración: el backfill (migración siguiente) la
 * setea para las sesiones existentes y la app la setea de aquí en adelante.
 * Se mantiene la unicidad por sede hasta que el backfill termine; el swap a
 * "una abierta por caja" lo hace una migración posterior (orden de archivos).
 *
 * `restrictOnDelete`: una caja con turnos NO se puede borrar (se archiva).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->uuid('cash_register_id')->nullable()->after('branch_id');

            $table->foreign('cash_register_id')
                ->references('id')->on('cash_registers')
                ->restrictOnDelete();
            $table->index('cash_register_id', 'cash_register_sessions_register_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->dropForeign(['cash_register_id']);
            $table->dropIndex('cash_register_sessions_register_idx');
            $table->dropColumn('cash_register_id');
        });
    }
};

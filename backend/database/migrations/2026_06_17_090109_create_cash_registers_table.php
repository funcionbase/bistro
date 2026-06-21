<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-caja por sede — catálogo de cajas físicas (Fase 1).
 *
 * Una caja es una entidad persistente con nombre ("Caja 1", "Barra",
 * "Domicilios"). Los turnos (`cash_register_sessions`) cuelgan de una caja, lo
 * que da identidad estable a los reportes históricos ("¿cómo cuadró la Barra
 * este mes?") y a la UX.
 *
 * Contable: las cajas NO se borran físicamente (archivan vía `archived_at`)
 * para preservar la FK desde sesiones/receipts históricos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit', 20);
            $table->uuid('branch_id');
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->timestamp('archived_at')->nullable();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['company_nit', 'branch_id'], 'cash_registers_company_branch_idx');
        });

        // Nombre único por sede entre cajas vigentes (las archivadas no compiten:
        // permite reusar "Caja 1" tras archivar la anterior).
        DB::statement('CREATE UNIQUE INDEX idx_cash_registers_unique_name
            ON cash_registers (company_nit, branch_id, name)
            WHERE archived_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_cash_registers_unique_name');
        Schema::dropIfExists('cash_registers');
    }
};

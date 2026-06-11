<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 12 — Metrics (analítica de escaneos).
 *
 * `menu_scan_events`: tabla simple (NO particionada). Append-only desde el
 * menú público vía `MenuController::recordScan()`. La retención (>90 días)
 * se aplica con `DELETE FROM menu_scan_events WHERE scanned_at < cutoff`
 * desde `DropOldMenuScanPartitionsJob` (conserva nombre legacy) en horario bajo.
 *
 * Decisión: el particionamiento RANGE mensual generaba una tabla
 * hija por mes (`menu_scan_events_2026_05`, `_06`, …) que crecía con el
 * tiempo y requería pre-warm + drain del default. Para el volumen real
 * del proyecto (escaneos públicos esporádicos) el costo operativo de
 * mantener particiones superaba el beneficio de query pruning.
 *
 * `menu_scan_daily_rollup`: agregación diaria; los reportes leen de aquí,
 * NUNCA de la tabla cruda. PK compuesta `(company_nit, scan_date, table_number, branch_id)`.
 *
 * branch_id NOT NULL desde el inicio en ambas tablas — `BranchScope`
 * requiere el contexto de sede para todas las consultas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_scan_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_nit', 32);
            $table->uuid('branch_id');
            $table->string('table_number', 16)->nullable();
            $table->timestampTz('scanned_at')->useCurrent();
            $table->uuid('session_id')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->binary('ip_hash')->nullable();
            $table->boolean('is_bot')->default(false);

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();

            $table->index(['company_nit', 'branch_id'], 'menu_scan_events_company_branch_idx');
            $table->index('scanned_at', 'menu_scan_events_scanned_at_idx');
            $table->index('session_id', 'menu_scan_events_session_idx');
        });

        Schema::create('menu_scan_daily_rollup', function (Blueprint $table): void {
            $table->string('company_nit', 32);
            $table->uuid('branch_id');
            $table->date('scan_date');
            $table->string('table_number', 16)->default('');
            $table->integer('total_scans')->default(0);
            $table->integer('unique_sessions')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['company_nit', 'scan_date', 'table_number', 'branch_id'], 'menu_scan_daily_rollup_pkey');

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();

            $table->index('scan_date', 'menu_scan_daily_rollup_scan_date_idx');
            $table->index(['company_nit', 'branch_id'], 'menu_scan_daily_rollup_company_branch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_scan_daily_rollup');
        Schema::dropIfExists('menu_scan_events');
    }
};

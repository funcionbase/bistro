<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque KDS — Tabla `kds_stations`.
 *
 * Estaciones de cocina por sede (caliente / fría / barra / fritos). Cada
 * `MenuCategory` puede mapearse a una estación (campo `kds_station_id` en
 * `restaurant_menus.structure.categories[]`). Los tickets del KDS se filtran
 * server-side por estación; cuando todos los items de una orden en una
 * estación quedan `ready`, se emite `markStationReady` y la lógica existente
 * de `KdsTicketService::maybePromoteOrderStatus` promueve la orden a `ready`.
 *
 * Reglas:
 *  - `branch_id` NOT NULL → estaciones son siempre por sede (BranchScope global).
 *  - `slug` único por `(company_nit, branch_id)`.
 *  - `is_default` señala la estación fallback para categorías sin mapeo.
 *  - `archived_at` para soft-archive (un device-token activo no es bloqueador
 *    de archivar — se debe revocar antes desde settings).
 *  - `color` en formato `#RRGGBB` (validación en Modelo + FormRequest).
 *  - SLA en minutos: `warn` < `alert` (validación en Modelo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kds_stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->string('slug', 64);
            $table->string('name');
            $table->string('color', 7)->default('#64748B');
            $table->unsignedSmallInteger('sla_warn_minutes')->default(8);
            $table->unsignedSmallInteger('sla_alert_minutes')->default(15);
            $table->boolean('is_default')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->unique(['company_nit', 'branch_id', 'slug'], 'kds_stations_company_branch_slug_unique');
            $table->index(['company_nit', 'branch_id', 'archived_at'], 'kds_stations_company_branch_archived_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kds_stations');
    }
};

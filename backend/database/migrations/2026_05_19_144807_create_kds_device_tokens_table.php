<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque KDS — Tabla `kds_device_tokens`.
 *
 * Tokens persistentes por dispositivo físico (tableta) asociados a una
 * estación específica de una sede. Se autentica con `Authorization: Bearer
 * <token>` o cookie HttpOnly `kds_device_token`; el middleware
 * `EnsureKdsDeviceToken` (alias `kds.device`) los resuelve sin sesión web
 * completa e inyecta `active_company_nit`, `active_branch_id`,
 * `active_station_id` en el request.
 *
 * Reglas:
 *  - `token_hash` SHA-256 del valor en claro. El claro se devuelve UNA sola
 *    vez al generar (copy-once en UI). No se persiste el claro.
 *  - `label` libre, máx 64 chars. Útil para describir la tableta ("Pereira -
 *    Caliente - Tablet 1").
 *  - `last_seen_at` y `last_ip` se actualizan en cada request autenticada.
 *  - `revoked_at` soft-revoke: el middleware rechaza tokens revocados con
 *    401. No se eliminan filas para conservar audit trail.
 *  - Unique `(token_hash)` previene colisiones.
 *  - Rate limit por token: 60 req/min (definido en AppServiceProvider).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kds_device_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->uuid('station_id');
            $table->string('token_hash', 64)->unique();
            $table->string('label', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('station_id')->references('id')->on('kds_stations')->cascadeOnDelete();
            $table->index(['company_nit', 'branch_id', 'station_id', 'revoked_at'], 'kds_device_tokens_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kds_device_tokens');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resoluciones DIAN por empresa.
 *
 * Una empresa típicamente tiene 2+ resoluciones activas al mismo tiempo:
 *  - Una para `pos_equivalent` (DEE POS) — caja, "consumidor final".
 *  - Una para `invoice` (FEV) — cliente con datos fiscales.
 *  - Eventualmente: `credit_note`, `debit_note`, `pos_equivalent_credit_note`.
 *
 * Cada resolución autoriza un prefijo + rango de consecutivos. El consecutivo
 * se asigna atómicamente vía `ResolutionConsecutiveAllocator`
 * (`SELECT ... FOR UPDATE` en `DB::transaction`, regla §2 del add-on
 * N-instance). La columna `current_number` lleva el contador; cuando
 * `current_number + 1 > range_to` el allocator lanza `ResolutionExhaustedException`.
 *
 * `technical_key` es la clave técnica DIAN entregada con la resolución;
 * entra al algoritmo SHA-384 del CUFE/CUDE. Se persiste cifrada con
 * `cast 'encrypted'` en el modelo (`DianResolution::casts()`).
 *
 * UNIQUE parcial garantiza una sola resolución activa por
 * (company_nit, document_type, environment). `is_active=false` permite
 * mantener histórico de resoluciones expiradas sin chocar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_resolutions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->string('document_type', 30);
            $table->string('prefix', 10);
            $table->unsignedBigInteger('range_from');
            $table->unsignedBigInteger('range_to');
            $table->unsignedBigInteger('current_number')->default(0);
            $table->string('resolution_number', 50);
            $table->date('valid_from');
            $table->date('valid_until');
            $table->text('technical_key');
            $table->string('environment', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->index(['company_nit', 'document_type']);
            $table->index(['company_nit', 'valid_until']);
        });

        // UNIQUE parcial: solo se permite UNA resolución activa por
        // (company_nit, document_type, environment) — el resto puede
        // co-existir como histórico (is_active=false).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX dian_resolutions_active_unique
                ON dian_resolutions (company_nit, document_type, environment)
                WHERE is_active = true
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS dian_resolutions_active_unique');
        }
        Schema::dropIfExists('dian_resolutions');
    }
};

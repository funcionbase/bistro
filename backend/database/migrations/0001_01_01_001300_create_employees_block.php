<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque Employees — Planificador de Turnos y Colaboradores.
 *
 *  - employee_positions: catálogo de cargos. is_system=true + company_nit=null
 *    para los del sistema (waiter, cook, etc.). Custom por empresa con
 *    is_system=false + company_nit poblado.
 *  - employees: perfil HHRR completo + datos contractuales. user_id nullable
 *    para colaboradores sin acceso al sistema. UNIQUE por (company_nit, email)
 *    y por (company_nit, doc_number) para evitar duplicados.
 *  - employees_branches: pivote para sedes auxiliares. La sede principal vive
 *    en employees.primary_branch_id (no se duplica acá).
 *  - employee_shifts: turnos asignados. starts_at/ends_at como timestamps
 *    para soportar turnos partidos y cruce de medianoche. Soft-cancel
 *    mantiene fila para auditoría.
 *  - company_workforce_settings: configuración 1:1 con la empresa (jornada
 *    máxima semanal, mínimo de días libres, modo de aviso).
 *
 * Convención contable: pay_rate y base_salary decimal(12,2). Inmutables vía
 * UPDATE; toda mutación pasa por DB::transaction + AuditService::log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit')->nullable();
            $table->string('slug');
            $table->string('label');
            $table->boolean('is_system')->default(false);
            $table->string('color', 7)->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->unique(['company_nit', 'slug']);
            $table->index('is_system');
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('primary_branch_id');
            $table->uuid('position_id')->nullable();

            $table->enum('doc_type', ['CC', 'CE', 'PA', 'PEP', 'TI']);
            $table->string('doc_number');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->date('birth_date')->nullable();
            $table->enum('blood_type', ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'])->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();

            $table->string('eps')->nullable();
            $table->string('arl')->nullable();
            $table->string('pension_fund')->nullable();
            $table->string('severance_fund')->nullable();

            $table->string('bank')->nullable();
            $table->enum('account_type', ['ahorros', 'corriente'])->nullable();
            $table->string('account_number')->nullable();

            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('uniform_size')->nullable();

            $table->enum('contract_type', ['fijo', 'indefinido', 'OPS', 'aprendizaje'])->nullable();
            $table->decimal('base_salary', 12, 2)->nullable();
            $table->enum('pay_type', ['hora', 'diario', 'semanal', 'quincenal', 'mensual']);
            $table->decimal('pay_rate', 12, 2)->default(0);
            $table->date('hire_date')->nullable();

            $table->enum('vinculation_status', ['active', 'inactive', 'vacation', 'sick_leave', 'compensatory'])
                ->default('active');
            $table->date('vinculation_valid_from')->nullable();
            $table->date('vinculation_valid_until')->nullable();

            $table->smallInteger('min_days_off_override')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('primary_branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('position_id')->references('id')->on('employee_positions')->nullOnDelete();

            $table->unique(['company_nit', 'doc_number']);
            $table->unique(['company_nit', 'email']);
            $table->index('primary_branch_id');
            $table->index('user_id');
            $table->index(['company_nit', 'vinculation_status']);
            $table->index('archived_at');
        });

        Schema::create('employees_branches', function (Blueprint $table) {
            $table->uuid('employee_id');
            $table->uuid('branch_id');
            $table->timestamps();

            $table->primary(['employee_id', 'branch_id']);
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });

        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->uuid('branch_id');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->enum('status', ['scheduled', 'cancelled'])->default('scheduled');
            $table->enum('cancellation_reason', ['sick', 'personal', 'emergency', 'vinculation_state', 'other'])
                ->nullable();
            $table->text('cancellation_note')->nullable();
            $table->foreignUuid('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();

            $table->index(['employee_id', 'starts_at']);
            $table->index(['branch_id', 'starts_at']);
            $table->index(['starts_at', 'status']);
        });

        // CHECK constraint: ends_at > starts_at. Lo aplicamos solo en Postgres
        // porque SQLite (utilizado en algunos entornos de testing local) no
        // soporta ADD CONSTRAINT en ALTER TABLE de forma uniforme.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_shifts ADD CONSTRAINT employee_shifts_ends_after_starts CHECK (ends_at > starts_at)');
        }

        Schema::create('company_workforce_settings', function (Blueprint $table) {
            $table->string('company_nit')->primary();
            $table->smallInteger('max_weekly_hours')->default(48);
            $table->smallInteger('min_days_off_per_week')->default(1);
            $table->enum('hours_warning_mode', ['warn', 'block', 'off'])->default('warn');
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_workforce_settings');
        Schema::dropIfExists('employee_shifts');
        Schema::dropIfExists('employees_branches');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('employee_positions');
    }
};

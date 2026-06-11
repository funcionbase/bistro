<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 05 — Business hours.
 *
 * Horarios operativos por empresa+sede:
 *  - business_hours: 7 filas por sede (day_of_week 0..6).
 *    UNIQUE (company_nit, branch_id, day_of_week).
 *  - business_hour_exceptions: días feriados/cerrados o con horario especial.
 *
 * branch_id NOT NULL desde el inicio (gestionados por BranchScope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hours', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->unsignedTinyInteger('day_of_week');
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->unique(['company_nit', 'branch_id', 'day_of_week'], 'business_hours_company_branch_day_unique');
            $table->index(['company_nit', 'branch_id'], 'business_hours_company_branch_idx');
        });

        Schema::create('business_hour_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->date('exception_date');
            $table->string('reason');
            $table->boolean('is_open')->default(false);
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->unique(['company_nit', 'branch_id', 'exception_date'], 'business_hour_exceptions_unique');
            $table->index(['company_nit', 'branch_id'], 'business_hour_exceptions_company_branch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_hour_exceptions');
        Schema::dropIfExists('business_hours');
    }
};

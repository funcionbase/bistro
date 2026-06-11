<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 03 — Branches.
 *
 * Sedes (locales físicos) bajo un mismo NIT y pivot user × branch.
 *
 *  - branches: PK uuid; slug único por empresa; soft-archive vía archived_at.
 *    is_default es informativo (sede principal marcada en onboarding).
 *  - branch_users: pivot user↔branch. Owners (role.is_system=true) hacen bypass
 *    de este pivot, pero los demás usuarios deben tener fila explícita.
 *
 * Timezone fijo America/Bogota a nivel app (no se persiste).
 *
 * NOTA: el resto de bloques (orders, menu, inventory, chat, etc.) crea sus
 * tablas con `branch_id uuid NOT NULL` desde el inicio porque ya tenemos
 * BranchScope global en runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->string('name');
            $table->string('slug');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->unique(['company_nit', 'slug']);
            $table->index(['company_nit', 'archived_at']);
        });

        Schema::create('branch_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_users');
        Schema::dropIfExists('branches');
    }
};

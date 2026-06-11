<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 06 — Menu.
 *
 * Menú del restaurante. Estructura JSON v3:
 *   { version: 3, categories: [{ id, name, description, order, items: [...] }] }
 *
 *  - restaurant_menus: 1 menú por sede. Estado active|draft|scheduled.
 *  - active_days: JSON con cron-like schedule. NULL = siempre activo.
 *  - branch_id NOT NULL desde el inicio (BranchScope global).
 *
 * Precios SIEMPRE se leen de structure JSON en BD; el frontend no inyecta precios.
 * Estructura precios en items: decimal lógico — el JSON no impone precisión, pero
 * los cálculos backend redondean a 2 decimales antes de persistir en orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_menus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->string('name', 128);
            $table->string('description', 512)->nullable();
            $table->enum('status', ['active', 'draft', 'scheduled'])->default('draft');
            $table->json('active_days')->nullable();
            $table->json('structure')->default('{"version":3,"categories":[]}');
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['company_nit', 'status']);
            $table->index(['company_nit', 'branch_id'], 'restaurant_menus_company_branch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_menus');
    }
};

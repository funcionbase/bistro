<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #246 Fase 1 — Catálogo de promo codes administrables (`promo_codes`).
 *
 * Reemplaza el modelo legacy `subscription_discounts` (descuento ad-hoc por
 * empresa) por dos tablas:
 *  - `promo_codes` (esta) — catálogo público con slug URL-amigable.
 *  - `company_promo_codes` — aplicación a empresas con snapshot.
 *
 * Aplicación: via enrollment (URL `?promo=...`), self-service (empresa
 * autenticada desde billing-tab) o GitHub Action (`company-ops.yml`).
 *
 * Constraints:
 *  - `code` único globalmente (UPPER, slug URL).
 *  - `discount_percent` BETWEEN 1 AND 100 (decisión #246 #1 — permite 100% = mes gratis).
 *  - `usage_count <= max_companies` (cuando max_companies != null) — enforced
 *    en service layer con `lockForUpdate`.
 *  - `starts_at < ends_at` (vigencia del código en sí).
 *
 * Soft-deleted (política #246 §9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->smallInteger('discount_percent');
            $table->smallInteger('months_duration');
            $table->integer('max_companies')->nullable();
            $table->integer('usage_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT chk_promo_codes_discount_percent CHECK (discount_percent BETWEEN 1 AND 100)');
            DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT chk_promo_codes_months_duration CHECK (months_duration BETWEEN 1 AND 120)');
            DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT chk_promo_codes_max_companies CHECK (max_companies IS NULL OR max_companies > 0)');
            DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT chk_promo_codes_vigency CHECK (starts_at IS NULL OR ends_at IS NULL OR starts_at <= ends_at)');
            DB::statement("ALTER TABLE promo_codes ADD CONSTRAINT chk_promo_codes_status CHECK (status IN ('active', 'inactive'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};

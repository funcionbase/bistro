<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guía de configuración inicial (#setup-guide).
 *
 * Agrega `setup_guide_dismissed_at` a `companies`: timestamp que registra
 * cuándo el owner/admin ocultó el widget de onboarding. NULL = visible;
 * NOT NULL = el usuario lo cerró y no vuelve a aparecer.
 *
 * Los pasos de la guía se auto-detectan desde el estado real del DB en
 * SetupGuideController::show() — este campo solo persiste el dismiss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->timestamp('setup_guide_dismissed_at')->nullable()->after('activation_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('setup_guide_dismissed_at');
        });
    }
};

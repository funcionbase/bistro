<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina la columna companies.plan.
 *
 * Cache denormalizado heredado del JWT legacy. La fuente de verdad del plan
 * vive en subscriptions (+ snapshots inmutables). Ya no se lee desde JwtService,
 * ActiveCompanyController, model ni seeders.
 *
 * down() recrea la columna nullable con default 'free' para reversibilidad.
 * Si tras rollback se necesita repoblar, correr `php artisan billing:backfill-default-plan`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('plan');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('plan')->nullable()->default('free');
        });
    }
};

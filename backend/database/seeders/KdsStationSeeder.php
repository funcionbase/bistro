<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\KdsStation;
use Illuminate\Database\Seeder;

/**
 * Backfill / siembra idempotente de estaciones KDS (#115) para todas las
 * sedes existentes.
 *
 * Para nuevas empresas/sedes, el seeding ocurre inline en
 * `CompanyEnrollmentController` y `BranchController::store`. Este seeder
 * cubre dos escenarios:
 *
 *  1. Dev / QA: garantiza que `RestauranteFlexySeeder` y otros fixtures
 *     tengan estaciones operables al final del run.
 *  2. PDN backfill: empresas que existían antes de #115 reciben las 4
 *     estaciones canónicas en su sede default al desplegar.
 *
 * Idempotente: `KdsStation::seedDefaultsForBranch` usa `firstOrCreate` por
 * `(company_nit, branch_id, slug)`. Re-ejecutar no duplica filas.
 */
class KdsStationSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::query()
            ->whereNull('archived_at')
            ->get(['id', 'company_nit', 'slug']);

        if ($branches->isEmpty()) {
            $this->command?->info('KdsStationSeeder: no hay sedes activas; nada que sembrar.');

            return;
        }

        foreach ($branches as $branch) {
            KdsStation::seedDefaultsForBranch($branch->company_nit, $branch->id);
        }

        $this->command?->info("KdsStationSeeder: {$branches->count()} sedes procesadas con 4 estaciones default cada una.");
    }
}

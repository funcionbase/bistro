<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyWorkforceSetting;
use Illuminate\Database\Seeder;

/**
 * Backfill 1:1 de `company_workforce_settings` para empresas existentes.
 *
 * Idempotente: usa `firstOrCreate` por `company_nit`. Las empresas nuevas
 * reciben la fila default en el flujo de `CompanyEnrollmentController` —
 * este seeder solo cubre el periodo previo al despliegue de #182.
 */
class WorkforceSettingsBackfillSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->pluck('nit')->each(function (string $nit) {
            CompanyWorkforceSetting::firstOrCreate(
                ['company_nit' => $nit],
                [
                    'max_weekly_hours' => 48,
                    'min_days_off_per_week' => 1,
                    'hours_warning_mode' => 'warn',
                ]
            );
        });
    }
}

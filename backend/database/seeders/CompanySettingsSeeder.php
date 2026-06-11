<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Services\CompanySettingsService;
use Illuminate\Database\Seeder;

class CompanySettingsSeeder extends Seeder
{
    public function __construct(
        private readonly CompanySettingsService $settingsService,
    ) {}

    public function run(): void
    {
        Company::query()->each(function (Company $company): void {
            $this->settingsService->seedDefaults($company->nit);
        });

        $this->command->info('Company settings seeded for all existing companies.');
    }
}

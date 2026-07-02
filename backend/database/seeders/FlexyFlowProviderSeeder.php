<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\DianProviderConfig;
use App\Models\DianResolution;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Configura flexyflow como empresa-proveedora SaaS para emisión DIAN (#246 PR-1.5).
 *
 * Asume que la Company de flexyflow ya existe (creada manualmente o por el flujo
 * de enrollment normal). Este seeder solo asegura:
 *  - Resoluciones DIAN para `invoice` y `credit_note` (idempotente firstOrCreate).
 *  - `DianProviderConfig` con `provider_slug='mock'` y `environment='habilitacion'`.
 *
 * En pdn NUNCA crea Company nueva: la empresa-proveedora debe registrarse
 * por el flujo normal de enrollment (con bank_id, evidencia, etc.) o
 * insertarse manualmente con valores completos. Esto evita que un seed
 * accidental ensucie `companies` con un NIT default.
 *
 * En dev/qa, si la Company no existe pero el operador definió
 * `FLEXYFLOW_NIT`, igual NO la crea — emite warn y skipea. Para tener una
 * Company flexyflow en local, usar el enrollment regular.
 *
 * Cuando se contrate Factura1/Siigo/Carvajal: solo cambia el `provider_slug`
 * de esta config en BD (rotación documentada en migration data por separado).
 */
class FlexyFlowProviderSeeder extends Seeder
{
    public function run(): void
    {
        $nit = (string) config('billing.flexyflow.nit');

        if ($nit === '') {
            $this->command?->warn('FLEXYFLOW_NIT no configurado — skip FlexyFlowProviderSeeder.');

            return;
        }

        $company = Company::query()->where('nit', $nit)->first();

        if ($company === null) {
            $this->command?->warn(
                "flexyflow Company NIT={$nit} no existe — skip resoluciones DIAN. ".
                'Registrá la empresa por el flujo normal y re-corré el seeder.'
            );

            return;
        }

        $this->ensureResolutions($company->nit);
        $this->ensureProviderConfig($company->nit);

        $this->command?->info("flexyflow SaaS provider: empresa {$company->nit} + resoluciones + MockProvider asegurados.");
    }

    private function ensureResolutions(string $nit): void
    {
        $base = [
            'company_nit' => $nit,
            'environment' => 'habilitacion',
            'valid_from' => now()->subMonths(3)->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
            'technical_key' => 'fc8eac422eba16e22ffd8c6f94b3f40a6e38162c',
            'is_active' => true,
            'range_from' => 1,
            'range_to' => 5000,
            'current_number' => 0,
        ];

        DianResolution::query()->firstOrCreate(
            [
                'company_nit' => $nit,
                'document_type' => 'invoice',
                'environment' => 'habilitacion',
                'is_active' => true,
            ],
            array_merge($base, [
                'document_type' => 'invoice',
                'prefix' => 'FEFF',
                'resolution_number' => '90000000001',
            ])
        );

        DianResolution::query()->firstOrCreate(
            [
                'company_nit' => $nit,
                'document_type' => 'credit_note',
                'environment' => 'habilitacion',
                'is_active' => true,
            ],
            array_merge($base, [
                'document_type' => 'credit_note',
                'prefix' => 'NCFEFF',
                'resolution_number' => '90000000002',
            ])
        );
    }

    private function ensureProviderConfig(string $nit): void
    {
        DianProviderConfig::query()->firstOrCreate(
            ['company_nit' => $nit, 'is_active' => true],
            [
                'provider_slug' => 'mock',
                'api_base_url' => null,
                'api_token_encrypted' => null,
                'software_id' => 'FLEXYFLOW-SAAS-'.Str::upper(Str::random(8)),
                'software_pin_encrypted' => Str::random(16),
                'test_set_id' => 'FLEXYFLOW-TESTSET-001',
                'environment' => 'habilitacion',
                'webhook_secret_encrypted' => Str::random(48),
            ]
        );
    }
}

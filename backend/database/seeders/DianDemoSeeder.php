<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\DianDefaultRecipient;
use App\Models\DianProviderConfig;
use App\Models\DianResolution;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Datos demo DIAN para cada `Company` existente (idempotente).
 *
 * Crea por empresa:
 *  - 1 resolución `pos_equivalent` (DEE POS, prefijo `PO`, rango 1..5000).
 *  - 1 resolución `invoice` (FEV, prefijo `FE`, rango 1..5000).
 *  - 1 `DianProviderConfig` activo con `provider_slug='mock'` y
 *    `environment='habilitacion'`. webhook_secret aleatorio (encrypted).
 *  - Completa el perfil fiscal mínimo de la empresa para que pueda emitir
 *    documentos válidos contra el mock (DV, CIIU, fiscal_responsibilities,
 *    municipio, datos de contacto fiscal).
 *  - NO crea `DianDefaultRecipient` — las empresas demo caen al consumidor
 *    final estándar de DIAN (`config('dian.default_final_consumer')`).
 *
 * Vive en QaSeeder + ProductionSeeder con guard: en producción solo escribe
 * si la empresa NO tiene config DIAN aún (idempotente strict).
 */
class DianDemoSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->each(function (Company $company): void {
            $this->ensureCompanyFiscalProfile($company);
            $this->ensureResolutions($company->nit);
            $this->ensureProviderConfig($company->nit);
        });

        $this->command?->info('DIAN demo: perfil fiscal + resoluciones + MockProvider seeded para todas las empresas.');
    }

    private function ensureCompanyFiscalProfile(Company $company): void
    {
        // Solo completa lo que esté vacío. No pisamos perfiles ya configurados
        // (idempotencia + respeto a configuración manual del owner).
        $patch = array_filter([
            'dv' => $company->dv ?? $this->computeDv($company->nit),
            'legal_representative_name' => $company->legal_representative_name ?? 'Representante Demo',
            'legal_representative_doc_type' => $company->legal_representative_doc_type ?? 'CC',
            'legal_representative_doc_number' => $company->legal_representative_doc_number ?? '1234567890',
            'economic_activity_code' => $company->economic_activity_code ?? '5611',
            'fiscal_responsibilities' => $company->fiscal_responsibilities ?: ['O-47', 'R-99-PN'],
            'tax_obligations' => $company->tax_obligations ?: [],
            'municipality_dane_code' => $company->municipality_dane_code ?? '66001',
            'billing_email' => $company->billing_email ?? 'facturacion@'.Str::slug($company->commercial_name).'.demo',
            'billing_phone' => $company->billing_phone ?? '+5713000000',
            'physical_address' => $company->physical_address ?? 'Cra 7 #20-45 Pereira',
        ], fn ($value) => $value !== null && $value !== []);

        if ($patch !== []) {
            $company->fill($patch)->save();
        }
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
                'document_type' => 'pos_equivalent',
                'environment' => 'habilitacion',
                'is_active' => true,
            ],
            array_merge($base, [
                'document_type' => 'pos_equivalent',
                'prefix' => 'PO',
                'resolution_number' => '18760000001',
            ])
        );

        DianResolution::query()->firstOrCreate(
            [
                'company_nit' => $nit,
                'document_type' => 'invoice',
                'environment' => 'habilitacion',
                'is_active' => true,
            ],
            array_merge($base, [
                'document_type' => 'invoice',
                'prefix' => 'FE',
                'resolution_number' => '18760000002',
            ])
        );

        // Notas crédito tienen su propio prefijo + rango. DIAN exige separar:
        // las NC NO comparten consecutivo con las facturas originales.
        DianResolution::query()->firstOrCreate(
            [
                'company_nit' => $nit,
                'document_type' => 'credit_note',
                'environment' => 'habilitacion',
                'is_active' => true,
            ],
            array_merge($base, [
                'document_type' => 'credit_note',
                'prefix' => 'NCFE',
                'resolution_number' => '18760000003',
            ])
        );

        DianResolution::query()->firstOrCreate(
            [
                'company_nit' => $nit,
                'document_type' => 'pos_equivalent_credit_note',
                'environment' => 'habilitacion',
                'is_active' => true,
            ],
            array_merge($base, [
                'document_type' => 'pos_equivalent_credit_note',
                'prefix' => 'NCPO',
                'resolution_number' => '18760000004',
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
                'software_id' => 'MOCK-SOFTWARE-'.Str::upper(Str::random(8)),
                'software_pin_encrypted' => Str::random(16),
                'test_set_id' => 'MOCK-TESTSET-001',
                'environment' => 'habilitacion',
                'webhook_secret_encrypted' => Str::random(48),
            ]
        );
    }

    /**
     * Cálculo del DV del NIT colombiano (algoritmo DIAN).
     * Solo aplica para NITs numéricos; caso contrario devuelve null.
     */
    private function computeDv(string $nit): ?string
    {
        $clean = preg_replace('/\D/', '', $nit) ?? '';
        if ($clean === '') {
            return null;
        }

        $weights = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $digits = array_map('intval', str_split(strrev($clean)));
        $sum = 0;
        foreach ($digits as $i => $d) {
            $sum += $d * ($weights[$i] ?? 0);
        }
        $r = $sum % 11;

        return (string) ($r >= 2 ? 11 - $r : $r);
    }
}

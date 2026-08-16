<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyWhatsappAccount;
use Illuminate\Database\Seeder;

/**
 * Pre-conecta el numero de prueba de Meta a la empresa demo del QaSeeder.
 *
 * Los identificadores del numero de prueba (Meta Developer Console) se leen
 * de variables de entorno — configuralos con los datos de tu propia app de
 * Meta en Meta for Developers > WhatsApp > API Setup.
 *
 * Asi cuando llegue un webhook real al ambiente QA, el resolver phone_number_id
 * → company_nit encuentra la empresa demo y crea/actualiza un Chat de WhatsApp.
 *
 * Idempotente: updateOrCreate sobre company_nit.
 */
class DemoWhatsappAccountSeeder extends Seeder
{
    private const DEMO_COMPANY_NIT = '1';

    public function run(): void
    {
        // Company PK ahora es id uuid; lookup explícito por nit.
        $company = Company::query()->where('nit', self::DEMO_COMPANY_NIT)->first();

        if (! $company) {
            $this->command?->warn('DemoWhatsappAccountSeeder: empresa demo (NIT=1) no existe. Saltando.');

            return;
        }

        CompanyWhatsappAccount::query()->updateOrCreate(
            ['company_nit' => $company->nit],
            [
                'provisioning_mode' => 'embedded_signup',
                'status' => 'connected',
                'waba_id' => env('META_TEST_WABA_ID', ''),
                'phone_number_id' => env('META_TEST_PHONE_NUMBER_ID', ''),
                'business_id' => env('META_BUSINESS_ID', ''),
                'phone_e164' => env('META_TEST_PHONE_E164', ''),
                'display_name' => 'SuperPapas (QA test)',
                'display_name_status' => 'APPROVED',
                'is_business_verified' => false,
                // Para QA usamos el system user token (mismo que ya esta en .env).
                'access_token_encrypted' => env('META_SYSTEM_USER_TOKEN', 'PLACEHOLDER_TEST_TOKEN'),
                'connected_at' => now(),
                'webhook_subscribed_at' => now(),
            ]
        );

        $this->command?->info('Numero de prueba de Meta conectado a empresa demo (NIT=1).');
    }
}

<?php

namespace Database\Seeders;

use App\Models\PromoCode;
use Illuminate\Database\Seeder;

/**
 * Promo de prueba gratuita por defecto — descuento 100% × 3 meses.
 *
 * `BillingService::activateCompany()` aplica este promo automáticamente cuando
 * la empresa pasa de `pending_activation` a `active` (vía `companies:approve`).
 * La duración del trial vive acá (`months_duration`), no en `.env` — para
 * cambiar el trial a 2 o 6 meses se edita esta fila y se re-corre el seeder.
 *
 * El slug que identifica este promo como "el trial" lo lee
 * `config('billing.trial_promo_code')`. `max_companies` NULL = sin tope (todas
 * las empresas nuevas reciben el trial).
 *
 * Idempotente: `updateOrCreate` por `code`. Re-correr reactiva el trial si se
 * desactivó por error operativo.
 */
class TrialPromoCodeSeeder extends Seeder
{
    public function run(): void
    {
        PromoCode::updateOrCreate(
            ['code' => (string) config('billing.trial_promo_code', 'TRIAL3')],
            [
                'name' => 'Prueba gratuita 3 meses',
                'description' => 'Descuento de bienvenida del 100% durante los primeros 3 meses. Se aplica automáticamente al activar la empresa.',
                'discount_percent' => 100,
                'months_duration' => 3,
                'max_companies' => null,
                'starts_at' => null,
                'ends_at' => null,
                'status' => 'active',
            ]
        );
    }
}

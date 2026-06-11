<?php

use Database\Seeders\TrialPromoCodeSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Siembra el promo de prueba gratuita (TRIAL3, 100% × 3 meses) en todos los
 * entornos vía deploy.
 *
 * NO es una migración de esquema: no crea ni altera columnas. Es el mecanismo
 * de entrega del dato a pdn — el pipeline de deploy corre `migrate --force`
 * pero NO `db:seed`, así que sin esto la fila no existiría en producción y
 * `BillingService::activateCompany` caería al trial legacy sin aplicar el promo.
 *
 * Delega en `TrialPromoCodeSeeder` (única fuente de verdad de los valores;
 * idempotente vía updateOrCreate). Mismo patrón que otros backfills de datos
 * del repo (p.ej. add_company_fiscal_profile_permission).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new TrialPromoCodeSeeder)->run();
    }

    public function down(): void
    {
        // Dato de referencia: no se elimina en el rollback. La fila puede estar
        // referenciada por company_promo_codes (FK restrictOnDelete) y el promo
        // se reusa entre deploys. Para retirarlo, desactivarlo vía ops
        // (`promo:toggle`), no por migración.
    }
};

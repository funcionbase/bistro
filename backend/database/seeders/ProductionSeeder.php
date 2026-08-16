<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder de produccion (PDN).
 *
 * SOLO datos de referencia inmutables: bancos, planes de facturacion, features
 * RBAC y templates de permisos. NO crea companias, usuarios, pedidos ni chats
 * — eso siempre lo hace el flujo real de la app.
 *
 * Idempotente: cada subsembrador usa updateOrCreate / guards de existencia, asi
 * que ejecutarlo varias veces es seguro y no duplica datos.
 *
 * Uso:
 *   php artisan db:seed --class=Database\\Seeders\\ProductionSeeder --force
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BankSeeder::class,
            FeatureSeeder::class,
            PermissionTemplateSeeder::class,
            // Catálogo de verticales (restaurant, bakery, cafe, etc.).
            // Idempotente; permite refrescar default_capabilities o agregar
            // verticales sin migración nueva.
            BusinessTypesSeeder::class,
            EmployeePositionSeeder::class,
            BillingPlanSeeder::class,
            // Promo de prueba gratuita por defecto (100% × 3 meses). Lo aplica
            // BillingService::activateCompany al activar la empresa. La duración
            // del trial vive en esta fila, no en BILLING_TRIAL_DAYS. Idempotente.
            TrialPromoCodeSeeder::class,
            // bistro como empresa-proveedora SaaS: resolución DIAN +
            // DianProviderConfig (mock por defecto). Idempotente. La empresa se
            // crea desde config('billing.bistro.*') si no existe.
            funcionbaseProviderSeeder::class,
            MetaPlatformCredentialsSeeder::class,
            // Backfills idempotentes de colaboradores: aseguran que empresas existentes
            // reciban los company_role_permissions nuevos y la fila de
            // workforce_settings. Para empresas nuevas, ambos puntos se cubren
            // en el flujo de enrollment.
            EmployeesFeatureBackfillSeeder::class,
            WorkforceSettingsBackfillSeeder::class,
            // Backfill idempotente de KDS: features kds.* a roles
            // is_system existentes + estaciones KDS canónicas
            // (caliente/fría/barra/fritos) por sede activa. Empresas nuevas
            // siembran inline en CompanyEnrollmentController y BranchController.
            // Para roles operativos no-system (cook, manager, etc.) correr
            // `php artisan roles:sync-templates` aparte.
            KdsFeatureBackfillSeeder::class,
            KdsStationSeeder::class,
        ]);
    }
}

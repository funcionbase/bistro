<?php

namespace Database\Seeders;

use App\Models\BillingPlan;
use Illuminate\Database\Seeder;

/**
 * Seeder del catálogo de planes SaaS.
 *
 * Decisión comercial 2026-07 — dos planes:
 *  - **Plan Básico** (slug `default`, is_default): $0 COP/mes. Uso completo de
 *    la plataforma sin costo mientras se construye el módulo de facturación
 *    electrónica DIAN. Reemplaza al "Plan Default" de $300.000.
 *  - **Plan Plus** (slug `plus`): $300.000 COP/mes + $10 COP por factura
 *    electrónica generada. Incluye el módulo DIAN. El cobro por factura
 *    ($10/unidad, parametrizado en `BILLING_DIAN_UNIT_PRICE`) se factura
 *    automáticamente cada mes junto con la mensualidad
 *    (`BillingService::generateMonthlyInvoices`).
 *
 * El slug del plan default sigue siendo `default` (config
 * `billing.default_plan_slug`) — solo cambian name/price/description.
 *
 * Idempotente: `updateOrCreate` por slug. Re-correr el seeder restaura el
 * catálogo al estado canónico.
 *
 * Los planes legacy se desactivan (no se borran) — sus subscriptions vivas
 * conservan referencia FK. El comando `billing:backfill-default-plan` migra
 * a esas subscriptions al plan default.
 */
class BillingPlanSeeder extends Seeder
{
    /** @var list<string> Features compartidas por ambos planes. */
    private const BASE_FEATURES = [
        'menu',
        'orders',
        'reports',
        'coupons',
        'deliveries',
        'metrics',
        'api',
        'multi_branch',
        'kds',
        'inventory',
        'crm',
        'chat',
    ];

    public function run(): void
    {
        // Plan Básico — el default: plataforma sin costo.
        BillingPlan::updateOrCreate(
            ['slug' => config('billing.default_plan_slug', 'default')],
            [
                'name' => 'Plan Básico',
                'description' => 'Acceso completo a la plataforma flexyflow sin costo. $0 COP/mes.',
                'price' => 0.00,
                'currency' => 'COP',
                'billing_cycle' => 'monthly',
                'features' => self::BASE_FEATURES,
                'is_active' => true,
                'is_default' => true,
                'price_includes_tax' => true,
                'tax_regime' => 'iva_19',
                'tax_rate' => 19.00,
                'sort_order' => 0,
            ]
        );

        // Plan Plus — incluye facturación electrónica DIAN.
        BillingPlan::updateOrCreate(
            ['slug' => 'plus'],
            [
                'name' => 'Plan Plus',
                'description' => 'Todo lo del Plan Básico + facturación electrónica DIAN. $300.000 COP/mes (IVA 19% incluido) + $10 COP por factura electrónica generada.',
                'price' => 300000.00,
                'currency' => 'COP',
                'billing_cycle' => 'monthly',
                'features' => [...self::BASE_FEATURES, 'dian'],
                'is_active' => true,
                'is_default' => false,
                'price_includes_tax' => true,
                'tax_regime' => 'iva_19',
                'tax_rate' => 19.00,
                'sort_order' => 1,
            ]
        );

        // Desactivar planes legacy si existen — preserva FKs pero sin nuevas suscripciones.
        BillingPlan::query()
            ->whereIn('slug', ['starter', 'basic', 'pro', 'enterprise'])
            ->update(['is_active' => false, 'is_default' => false]);
    }
}

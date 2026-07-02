<?php

namespace Database\Seeders;

use App\Models\BillingPlan;
use Illuminate\Database\Seeder;

/**
 * Seeder del catálogo de planes SaaS.
 *
 * #246 — reemplazó los 4 planes legacy (Starter/Básico/Pro/Enterprise) por
 * UN plan único "Default" de $300.000 COP/mes (IVA 19% incluido). Decisión
 * comercial: pricing por uso de plataforma sin tiers, descuentos via promo
 * codes.
 *
 * Idempotente: `updateOrCreate` por slug. Re-correr el seeder reactiva el
 * plan default si se desactivó por error operativo.
 *
 * Los planes legacy se desactivan (no se borran) — sus subscriptions vivas
 * conservan referencia FK. El comando `billing:backfill-default-plan` migra
 * a esas subscriptions al plan default.
 */
class BillingPlanSeeder extends Seeder
{
    public function run(): void
    {
        // Plan default — el único activo desde #246.
        BillingPlan::updateOrCreate(
            ['slug' => config('billing.default_plan_slug', 'default')],
            [
                'name' => 'Plan Default',
                'description' => 'Acceso completo a la plataforma flexyflow. $300.000 COP/mes (IVA 19% incluido).',
                'price' => 300000.00,
                'currency' => 'COP',
                'billing_cycle' => 'monthly',
                'features' => [
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
                ],
                'is_active' => true,
                'is_default' => true,
                'price_includes_tax' => true,
                'tax_regime' => 'iva_19',
                'tax_rate' => 19.00,
                'sort_order' => 0,
            ]
        );

        // Desactivar planes legacy si existen — preserva FKs pero sin nuevas suscripciones.
        BillingPlan::query()
            ->whereIn('slug', ['starter', 'basic', 'pro', 'enterprise'])
            ->update(['is_active' => false, 'is_default' => false]);
    }
}

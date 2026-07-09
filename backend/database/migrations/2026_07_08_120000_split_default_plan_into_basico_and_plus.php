<?php

use Database\Seeders\BillingPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Catálogo de planes 2026-07: "Plan Default" ($300.000) pasa a "Plan Básico"
 * ($0 COP/mes) y nace "Plan Plus" ($300.000 COP/mes + $10 COP por factura
 * electrónica generada, incluye módulo DIAN).
 *
 * Decisión comercial: la plataforma es gratis para todas las empresas; solo se
 * cobra el módulo de facturación electrónica DIAN vía Plan Plus. El cambio de
 * plan de una empresa se opera con `billing:change-plan` (workflow
 * `bistro-ops-company-plan.yml`).
 *
 * NO es una migración de esquema: delega en `BillingPlanSeeder` (única fuente
 * de verdad del catálogo; idempotente vía updateOrCreate por slug). El deploy
 * corre `migrate --force` pero no `db:seed` — este es el mecanismo de entrega
 * a pdn. Los snapshots de subscriptions vivas los actualiza la migración
 * siguiente (backfill_subscriptions_to_plan_basico).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new BillingPlanSeeder)->run();

        // updateOrCreate pasa por el observer `saved` (invalida el cache),
        // pero el UPDATE bulk de legacy no — forget defensivo.
        Cache::forget('billing_plans.default');
    }

    public function down(): void
    {
        // Catálogo de referencia: no se revierte por migración. El estado
        // anterior (Plan Default $300.000) queda en git history del seeder.
    }
};

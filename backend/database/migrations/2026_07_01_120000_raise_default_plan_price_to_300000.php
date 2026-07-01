<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sube el precio del plan default SaaS de $100.000 a $300.000 COP/mes
 * (IVA 19% incluido). Decisión comercial: nuevo precio de uso de la plataforma.
 *
 * Alcance de ESTA migración: solo el catálogo `billing_plans` → afecta a las
 * empresas que se activen desde ahora (su `plan_price_snapshot` nace en 300k).
 *
 * Las empresas YA activas NO se tocan acá: facturan por el snapshot de su
 * subscription y su alza se aplica con el comando `billing:apply-plan-price-hike`,
 * ejecutado a mano UNA vez el 2026-07-01. Los invoices ya emitidos son
 * inmutables (DIAN) y nunca se modifican.
 *
 * Idempotente (guarda por precio viejo exacto) y reversible.
 */
return new class extends Migration
{
    private const OLD_PRICE = 100000.00;

    private const NEW_PRICE = 300000.00;

    private const OLD_DESCRIPTION = 'Acceso completo a la plataforma FlexyFlow. $100.000 COP/mes (IVA 19% incluido).';

    private const NEW_DESCRIPTION = 'Acceso completo a la plataforma FlexyFlow. $300.000 COP/mes (IVA 19% incluido).';

    public function up(): void
    {
        $this->reprice(self::OLD_PRICE, self::NEW_PRICE, self::NEW_DESCRIPTION);
    }

    public function down(): void
    {
        $this->reprice(self::NEW_PRICE, self::OLD_PRICE, self::OLD_DESCRIPTION);
    }

    private function reprice(float $from, float $to, string $toDescription): void
    {
        $slug = config('billing.default_plan_slug', 'default');

        DB::table('billing_plans')
            ->where('slug', $slug)
            ->where('price', $from)
            ->update([
                'price' => $to,
                'description' => $toDescription,
                'updated_at' => now(),
            ]);

        // El update crudo salta el observer `saved` del modelo — invalidar a mano.
        Cache::forget('billing_plans.default');
    }
};

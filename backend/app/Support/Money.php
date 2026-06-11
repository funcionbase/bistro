<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Redondeo bancario (banker's rounding) para montos monetarios.
 *
 * Implementa la convención IEEE 754 "round half to even" (`PHP_ROUND_HALF_EVEN`)
 * exigida por la cultura contable colombiana para minimizar sesgo en cierres
 * agregados. Documentado en `constants/ACCOUNTING_RULES.md`.
 *
 * Uso obligatorio en cualquier cálculo que produzca un monto persistido
 * (BillingService, PromoCodeService, DianXmlBuilder). Reemplaza `round()`
 * con redondeo aritmético clásico (`PHP_ROUND_HALF_UP`).
 */
final class Money
{
    /**
     * Redondea un valor monetario a la escala dada (default 2 decimales COP)
     * usando PHP_ROUND_HALF_EVEN.
     */
    public static function round(float|int|string $value, int $scale = 2): float
    {
        return round((float) $value, $scale, PHP_ROUND_HALF_EVEN);
    }

    /**
     * Suma N valores con redondeo bancario aplicado a cada operando y al total.
     * Útil para componer totales de líneas de factura sin acumular drift.
     *
     * @param  array<int, float|int|string>  $values
     */
    public static function sum(array $values, int $scale = 2): float
    {
        $total = 0.0;
        foreach ($values as $v) {
            $total += self::round($v, $scale);
        }

        return self::round($total, $scale);
    }

    /**
     * Aplica un porcentaje a una base monetaria (descuentos, impuestos).
     * `applyPercent(100000, 19)` → `19000.00`.
     */
    public static function applyPercent(float|int|string $base, float|int|string $percent, int $scale = 2): float
    {
        return self::round((float) $base * ((float) $percent / 100.0), $scale);
    }

    /**
     * Extrae la base gravable de un monto bruto que incluye un impuesto.
     * `extractBase(100000, 19)` → `84033.61` (PHP_ROUND_HALF_EVEN sobre 1.19).
     */
    public static function extractBase(float|int|string $gross, float|int|string $taxRate, int $scale = 2): float
    {
        $rate = (float) $taxRate;
        if ($rate <= 0.0) {
            return self::round($gross, $scale);
        }

        return self::round((float) $gross / (1.0 + ($rate / 100.0)), $scale);
    }
}

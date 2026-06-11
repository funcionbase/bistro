/**
 * Redondeo bancario (PHP_ROUND_HALF_EVEN espejo) y helpers monetarios.
 *
 * Espejo de `App\Support\Money` (PHP). Mantener sincronizado: si cambia uno, cambia el otro.
 * Política contable CO documentada en `constants/ACCOUNTING_RULES.md`.
 *
 * Por qué bankers rounding: en cierres agregados de muchos importes el redondeo
 * clásico ("half up") introduce sesgo positivo. "Half to even" lo neutraliza
 * promediando la decisión sobre números pares.
 */

const pow10 = (scale: number): number => 10 ** scale;

/**
 * Redondea a `scale` decimales (default 2) usando half-to-even (banker's rounding).
 *
 * Implementación numérica: escalamos, comparamos el residuo decimal y, ante
 * empate exacto (0.5), elegimos el entero par.
 */
export function roundMoney(value: number, scale = 2): number {
    if (!Number.isFinite(value)) {
        return 0;
    }
    const factor = pow10(scale);
    const scaled = value * factor;
    const floor = Math.floor(scaled);
    const diff = scaled - floor;
    const EPSILON = 1e-9;

    if (Math.abs(diff - 0.5) < EPSILON) {
        return (floor % 2 === 0 ? floor : floor + 1) / factor;
    }
    return Math.round(scaled) / factor;
}

export function sumMoney(values: readonly number[], scale = 2): number {
    return roundMoney(
        values.reduce((acc, v) => acc + roundMoney(v, scale), 0),
        scale,
    );
}

export function applyPercent(base: number, percent: number, scale = 2): number {
    return roundMoney((base * percent) / 100, scale);
}

/**
 * `extractBase(100000, 19)` → `84033.61` — extrae base gravable de un bruto.
 */
export function extractBase(gross: number, taxRate: number, scale = 2): number {
    if (taxRate <= 0) {
        return roundMoney(gross, scale);
    }
    return roundMoney(gross / (1 + taxRate / 100), scale);
}

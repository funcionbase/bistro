<?php

declare(strict_types=1);

namespace App\Support\Nit;

/**
 * Calculador del Dígito de Verificación (DV) del NIT colombiano (algoritmo DIAN).
 *
 * Factores oficiales: `[3,7,13,17,19,23,29,37,41,43,47,53,59,67,71]` aplicados de
 * derecha a izquierda sobre los dígitos del NIT (sin el DV). Suma ponderada,
 * `mod 11`; DV = `mod` si `mod < 2`, sino `11 - mod`.
 *
 * Sin dependencias externas — todos los cálculos son locales. El espejo TS
 * vive en `application/frontend/src/lib/nit-dv.ts` para autocompletado en el
 * enrollment wizard.
 */
final class DvCalculator
{
    /**
     * Factores DIAN aplicados de derecha a izquierda al NIT.
     *
     * @var list<int>
     */
    private const FACTORS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    /**
     * Calcula el DV del NIT dado (solo dígitos, sin DV).
     * Retorna un entero 0..10. Tira `InvalidArgumentException` si la entrada
     * no tiene dígitos.
     */
    public static function compute(string $nit): int
    {
        $digits = preg_replace('/\D+/', '', $nit) ?? '';
        if ($digits === '') {
            throw new \InvalidArgumentException('NIT debe contener al menos un dígito.');
        }

        $reversed = strrev($digits);
        $sum = 0;
        $len = strlen($reversed);

        for ($i = 0; $i < $len; $i++) {
            $factor = self::FACTORS[$i] ?? 0;
            if ($factor === 0) {
                break;
            }
            $sum += ((int) $reversed[$i]) * $factor;
        }

        $mod = $sum % 11;

        return $mod < 2 ? $mod : 11 - $mod;
    }

    /**
     * Valida que el DV provisto coincida con el calculado.
     */
    public static function isValid(string $nit, int|string $providedDv): bool
    {
        $providedInt = (int) $providedDv;

        return self::compute($nit) === $providedInt;
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Conversiones entre unidades compatibles del módulo de inventario.
 *
 * Dimensiones:
 *  - masa: kg ↔ g (factor 1000)
 *  - volumen: l ↔ ml (factor 1000)
 *  - cuenta: un ↔ un (sin conversión)
 *
 * Dimensiones distintas (p. ej. recipe.kg vs ingredient.l) lanzan
 * ValidationException — la receta debe corregirse manualmente. Usamos bcmath
 * para evitar drift de centésimas/milésimas en cantidades pequeñas.
 */
final class UnitConverter
{
    /** @var array<string, string> */
    private const DIMENSIONS = [
        'kg' => 'mass',
        'g' => 'mass',
        'l' => 'volume',
        'ml' => 'volume',
        'un' => 'count',
    ];

    /**
     * Convierte `quantity` desde `fromUnit` a `toUnit`. Devuelve string decimal
     * con 3 decimales (alineado a `ingredients.current_stock`).
     *
     * @throws ValidationException si las unidades no son convertibles entre sí.
     */
    public static function convert(string $quantity, string $fromUnit, string $toUnit): string
    {
        if ($fromUnit === $toUnit) {
            return bcadd($quantity, '0', 3);
        }

        $fromDim = self::DIMENSIONS[$fromUnit] ?? null;
        $toDim = self::DIMENSIONS[$toUnit] ?? null;

        if ($fromDim === null || $toDim === null) {
            throw ValidationException::withMessages([
                'unit' => "Unidad desconocida: {$fromUnit} → {$toUnit}.",
            ]);
        }

        if ($fromDim !== $toDim) {
            throw ValidationException::withMessages([
                'unit' => "No se puede convertir {$fromUnit} a {$toUnit} (dimensiones distintas).",
            ]);
        }

        // Misma dimensión, distinta escala. kg→g multiplica por 1000; g→kg divide.
        $factor = match ([$fromUnit, $toUnit]) {
            ['kg', 'g'], ['l', 'ml'] => '1000',
            ['g', 'kg'], ['ml', 'l'] => '0.001',
            default => null,
        };

        if ($factor === null) {
            // Defensa: par válido por dimensión pero no contemplado arriba.
            throw ValidationException::withMessages([
                'unit' => "Conversión {$fromUnit} → {$toUnit} no soportada.",
            ]);
        }

        return bcmul($quantity, $factor, 3);
    }

    public static function areCompatible(string $a, string $b): bool
    {
        $dimA = self::DIMENSIONS[$a] ?? null;
        $dimB = self::DIMENSIONS[$b] ?? null;

        return $dimA !== null && $dimA === $dimB;
    }
}

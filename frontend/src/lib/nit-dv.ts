/**
 * Cálculo del Dígito de Verificación (DV) del NIT colombiano — algoritmo DIAN.
 *
 * Espejo de `App\Support\Nit\DvCalculator` (PHP). Sin red — todo local.
 * Usado para autocompletado del DV en el enrollment wizard (paso fiscal).
 *
 * Factores oficiales: `[3,7,13,17,19,23,29,37,41,43,47,53,59,67,71]` aplicados
 * derecha → izquierda. Suma ponderada, mod 11. DV = mod si mod < 2, sino 11-mod.
 */
const FACTORS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71] as const;

/**
 * Calcula el DV del NIT (string de dígitos o NIT con guiones/espacios — se filtran).
 * Retorna entero 0..10. Lanza si la entrada no tiene dígitos.
 */
export function computeNitDv(nit: string): number {
    const digits = String(nit).replace(/\D+/g, '');
    if (digits.length === 0) {
        throw new Error('NIT debe contener al menos un dígito.');
    }

    const reversed = digits.split('').reverse();
    let sum = 0;

    for (let i = 0; i < reversed.length; i++) {
        const factor = FACTORS[i];
        if (factor === undefined) {
            break;
        }
        sum += Number(reversed[i]) * factor;
    }

    const mod = sum % 11;
    return mod < 2 ? mod : 11 - mod;
}

export function isNitDvValid(nit: string, providedDv: number | string): boolean {
    return computeNitDv(nit) === Number(providedDv);
}

/**
 * Versión safe que no tira excepción — útil en onChange handlers.
 * Retorna `null` si el NIT está vacío o no es válido.
 */
export function tryComputeNitDv(nit: string): number | null {
    try {
        return computeNitDv(nit);
    } catch {
        return null;
    }
}

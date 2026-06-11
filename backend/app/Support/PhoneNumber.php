<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalización de teléfonos a formato E.164 (`+<cc><número>`), exigido por
 * Amazon SNS para publicar SMS (#275).
 *
 * El cajero puede tipear el número con o sin prefijo internacional. Si no
 * incluye prefijo, asumimos Colombia (`+57`) — todas las empresas operan en CO.
 * La normalización vive en backend (fuente de verdad); el frontend solo da un
 * hint visual.
 *
 * Diseño deliberadamente conservador: ante ambigüedad devolvemos `null`
 * (número inválido → no se envía SMS, sin romper el flujo de la orden) en
 * lugar de fabricar un número que SNS rechazaría igual.
 */
final class PhoneNumber
{
    /** Código de país por defecto (Colombia) cuando el número no trae prefijo. */
    public const DEFAULT_COUNTRY_CODE = '57';

    /** Rango válido de dígitos de un número E.164 (sin contar el `+`). */
    private const E164_MIN_DIGITS = 8;

    private const E164_MAX_DIGITS = 15;

    /**
     * Convierte un teléfono crudo a E.164 o devuelve null si es inválido.
     *
     * Reglas:
     *  - `+57 300 123 4567` / `+573001234567` → ya internacional → `+573001234567`.
     *  - `573001234567` (cc sin `+`, > 10 dígitos) → se asume ya prefijado → `+573001234567`.
     *  - `3001234567` (local) → se antepone el cc por defecto → `+573001234567`.
     */
    public static function toE164(string $raw, string $defaultCountryCode = self::DEFAULT_COUNTRY_CODE): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $hasPlus = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        if ($digits === '') {
            return null;
        }

        if ($hasPlus) {
            $candidate = $digits;
        } elseif (str_starts_with($digits, $defaultCountryCode) && strlen($digits) > 10) {
            // Trae el código de país pero sin el `+` (ej. 573001234567).
            $candidate = $digits;
        } else {
            // Número local → anteponer el código de país por defecto.
            $candidate = $defaultCountryCode.$digits;
        }

        $length = strlen($candidate);
        if ($length < self::E164_MIN_DIGITS || $length > self::E164_MAX_DIGITS) {
            return null;
        }

        return '+'.$candidate;
    }

    /**
     * Enmascara un teléfono para logs/auditoría dejando visibles solo los
     * últimos 4 dígitos (ej. `+57·······4567`). Nunca persistir el número
     * completo en `audit_logs`.
     */
    public static function mask(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '****';
        }

        $visible = substr($digits, -4);
        $hidden = str_repeat('•', max(0, strlen($digits) - 4));

        return $hidden.$visible;
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Redacción de PII antes de que un texto llegue a un log o a `webhook_events`.
 *
 * Existe por un caso concreto y verificado, no por precaución: el mensaje de una
 * excepción de PostgreSQL incluye `DETAIL: Failing row contains (...)` con la
 * FILA ENTERA. Sobre `chats` o `contacts` eso son el teléfono y el nombre del
 * cliente; sobre `chat_messages`, el cuerpo del mensaje. Loguear
 * `$e->getMessage()` crudo manda todo eso a CloudWatch, que no tiene ni la
 * retención ni los controles de acceso de la base — y viola la regla dura de
 * §12 del plan ("prohibido loguear `body` y `client_phone`").
 *
 * Evidencia del incidente que lo motivó: durante la verificación de F2, ocho
 * líneas de `laravel.log` quedaron con un teléfono real y el nombre del cliente.
 */
final class RedactsPii
{
    /**
     * Corta el `DETAIL:` de PostgreSQL y enmascara cualquier teléfono suelto que
     * quede en el resto del texto.
     *
     * Se corta en vez de intentar limpiar el DETAIL campo por campo: el orden y
     * el tipo de las columnas cambian con cada migración, así que cualquier
     * parser quedaría desactualizado en silencio. Lo que importa del mensaje
     * —qué constraint falló— está SIEMPRE antes del DETAIL.
     */
    public static function exceptionMessage(string $message, int $maxLength = 2000): string
    {
        foreach (['DETAIL:', 'Failing row contains'] as $marker) {
            $position = mb_strpos($message, $marker);

            if ($position !== false) {
                $message = rtrim(mb_substr($message, 0, $position)).' [detalle omitido: contenía datos de la fila]';
                break;
            }
        }

        return mb_substr(self::phones($message), 0, $maxLength);
    }

    /**
     * Enmascara teléfonos dejando los últimos 4 dígitos: alcanza para
     * correlacionar dos eventos del mismo cliente sin almacenar el número.
     */
    public static function phones(string $text): string
    {
        return (string) preg_replace_callback(
            '/\+?\d[\d\s\-]{7,19}\d/',
            static function (array $m): string {
                $digits = preg_replace('/\D/', '', $m[0]) ?? '';

                return strlen($digits) >= 8 ? '***'.substr($digits, -4) : $m[0];
            },
            $text,
        );
    }
}

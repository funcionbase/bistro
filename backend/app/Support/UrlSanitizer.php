<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Neutraliza esquemas de URL peligrosos en texto libre de chat (mensajes que se
 * reciben o envían por cualquier canal). Defensa en profundidad: el body se
 * renderiza como texto plano (React escapa) y los únicos hrefs que arma el
 * frontend son http/https propios, pero sanear en el punto de PERSISTENCIA evita
 * que una URL ejecutable quede almacenada y se propague si algún día el texto se
 * vuelve clickeable o se reusa en otro contexto (comanda, PDF, email).
 *
 * NO altera http/https ni el texto legible: solo rompe el `:` de los esquemas
 * que nunca deben ejecutarse (`javascript:` → `javascript_:`), y quita bytes de
 * control (excepto tab/newline/CR, que son legítimos en un mensaje).
 */
class UrlSanitizer
{
    /** Esquemas que jamás deben ser una URL clickeable. */
    private const DANGEROUS_SCHEMES = ['javascript', 'data', 'vbscript', 'file', 'blob', 'about'];

    public static function neutralizeDangerousSchemes(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        // 1. Quita bytes de control salvo \t (09), \n (0A), \r (0D): un
        //    `\x01javascript:` se lee como `javascript:` en muchos parsers.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // 2. Rompe el `:` de los esquemas peligrosos (tolera espacios antes del
        //    `:`, evasión clásica `javascript :`). Case-insensitive. El `_` deja
        //    el texto legible pero inerte como URL.
        $schemes = implode('|', self::DANGEROUS_SCHEMES);

        return (string) preg_replace_callback(
            '/\b('.$schemes.')\s*:/i',
            static fn (array $m): string => $m[1].'_:',
            $text,
        );
    }
}

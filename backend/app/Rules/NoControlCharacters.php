<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rechaza caracteres de control invisibles y bidi overrides usados
 * para spoofing visual.
 *
 * Bloquea:
 * - U+0000–U+001F (control characters) — excepto \t (U+0009) y \n (U+000A)
 *   cuando se construye con `allowWhitespace: true`.
 * - U+007F (DEL).
 * - U+202A–U+202E (bidi overrides — `pwnd‮gpj.exe` style spoofing).
 *
 * Permite explícitamente:
 * - U+200E, U+200F (LRM, RLM) — markers de direccionalidad legítimos.
 * - Emojis (no son control chars).
 *
 * Ver `docs/wiki/SECURITY_INPUT_HANDLING.md` para política completa.
 */
class NoControlCharacters implements ValidationRule
{
    public function __construct(public bool $allowWhitespace = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $pattern = $this->allowWhitespace
            ? '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{202A}-\x{202E}]/u'
            : '/[\x{0000}-\x{001F}\x{007F}\x{202A}-\x{202E}]/u';

        if (preg_match($pattern, $value) === 1) {
            $fail('El campo :attribute contiene caracteres no permitidos.');
        }
    }
}

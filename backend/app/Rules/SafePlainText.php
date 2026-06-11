<?php

namespace App\Rules;

use App\Http\Requests\Concerns\SanitizesInput;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Normalizer;

/**
 * Regla compuesta para texto plano de usuario:
 *
 * 1. Rechaza tipo no-string.
 * 2. Mide longitud en **bytes** (no chars) para que payloads con muchos
 *    emojis no exploten el cap real de la columna.
 * 3. Valida ausencia de control characters / bidi overrides vía
 *    {@see NoControlCharacters}.
 *
 * La sanitización (strip_tags + NFC + trim) ocurre en el trait
 * {@see SanitizesInput} antes de la
 * validación. Esta regla es defensa redundante por si alguna
 * FormRequest no usa el trait.
 *
 * Ver `docs/wiki/SECURITY_INPUT_HANDLING.md` para política completa.
 */
class SafePlainText implements ValidationRule
{
    public function __construct(
        public int $maxBytes,
        public bool $allowWhitespace = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('El campo :attribute debe ser texto.');

            return;
        }

        if (strlen($value) > $this->maxBytes) {
            $fail('El campo :attribute excede el tamaño máximo permitido.');

            return;
        }

        (new NoControlCharacters(allowWhitespace: $this->allowWhitespace))
            ->validate($attribute, $value, $fail);
    }

    /**
     * Aplica el saneamiento canónico a un string. Útil desde el trait
     * `SanitizesInput` y como helper estandalone.
     */
    public static function sanitize(string $value, bool $allowWhitespace = false): string
    {
        $stripped = strip_tags($value);

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($stripped, Normalizer::FORM_C);
            if ($normalized !== false) {
                $stripped = $normalized;
            }
        }

        if (! $allowWhitespace) {
            $stripped = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;
        }

        return trim($stripped);
    }
}

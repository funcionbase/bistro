<?php

namespace App\Http\Requests\Concerns;

use App\Rules\SafePlainText;
use Illuminate\Support\Str;
use Normalizer;

/**
 * Trait para FormRequests que declaran un mapa de categorías de
 * saneamiento sobre sus campos.
 *
 * Ejemplo de uso:
 *
 * ```php
 * class StoreChatMessageRequest extends FormRequest
 * {
 *     use SanitizesInput;
 *
 *     protected array $sanitize = [
 *         'body' => 'plain_text_long',
 *         'contact_name' => 'plain_text_short',
 *     ];
 *
 *     public function rules(): array
 *     {
 *         return [
 *             'body' => ['required', new SafePlainText(maxBytes: 4000, allowWhitespace: true)],
 *             'contact_name' => ['required', new SafePlainText(maxBytes: 255)],
 *         ];
 *     }
 * }
 * ```
 *
 * Categorías soportadas:
 * - `plain_text_short`: strip_tags + NFC + colapsa whitespace + trim.
 * - `plain_text_long`: strip_tags + NFC + trim (preserva `\n` y `\t`).
 * - `markdown_trusted`: passthrough (NO sanitiza — reservado para contenido staff-curated; sin uso activo desde que los documentos legales viven en el wiki externo).
 * - `identifier`: lowercase + trim (la regex de validation hace el resto).
 * - `json_payload`: passthrough (el array ya se valida por esquema).
 *
 * Ver `docs/wiki/SECURITY_INPUT_HANDLING.md` para política completa.
 */
trait SanitizesInput
{
    /**
     * Hook por default. Si la FormRequest define su propio
     * prepareForValidation(), debe llamar a sanitizeMappedFields() al
     * comienzo:
     *
     * ```php
     * protected function prepareForValidation(): void
     * {
     *     $this->sanitizeMappedFields();
     *     // ...lógica custom
     * }
     * ```
     */
    protected function prepareForValidation(): void
    {
        $this->sanitizeMappedFields();
    }

    protected function sanitizeMappedFields(): void
    {
        $map = $this->sanitize ?? [];

        if (empty($map)) {
            return;
        }

        $merge = [];

        foreach ($map as $field => $category) {
            $value = $this->input($field);

            if ($value === null) {
                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            $merge[$field] = $this->applySanitization($value, $category);
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    protected function applySanitization(string $value, string $category): string
    {
        return match ($category) {
            'plain_text_short' => SafePlainText::sanitize($value, allowWhitespace: false),
            'plain_text_long' => SafePlainText::sanitize($value, allowWhitespace: true),
            'identifier' => Str::lower(trim($value)),
            'markdown_trusted', 'json_payload' => $this->normalize($value),
            default => $this->normalize($value),
        };
    }

    protected function normalize(string $value): string
    {
        if (! class_exists(Normalizer::class)) {
            return $value;
        }

        $result = Normalizer::normalize($value, Normalizer::FORM_C);

        return $result === false ? $value : $result;
    }
}

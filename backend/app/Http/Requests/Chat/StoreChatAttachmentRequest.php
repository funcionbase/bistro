<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Adjunto saliente de un chat (plan 8-whatsapp.md §6.7).
 *
 * Borde de confianza: el archivo llega del navegador. Todo lo de acá es
 * innegociable, no "validación de conveniencia".
 *
 *  - El MIME se detecta SERVER-SIDE del contenido (`getMimeType()` usa finfo),
 *    nunca de la extensión del nombre, que la controla el cliente.
 *  - Lista blanca por tipo. SVG y HTML quedan fuera: se sirven desde nuestro
 *    dominio y ejecutan script (XSS). Tampoco ejecutables.
 *  - Tope propio de 16 MB independiente de lo que acepte WhatsApp: el host es
 *    compartido y el egreso de S3 se paga.
 *  - El nombre del archivo se sanea y se usa SOLO como metadato. La clave en S3
 *    la genera el servidor.
 */
class StoreChatAttachmentRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'caption' => 'plain_text_long',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $maxKilobytes = (int) floor(((int) config('evolution.media.max_bytes')) / 1024);

        return [
            'file' => ['required', 'file', 'max:'.$maxKilobytes],
            'type' => ['required', 'string', 'in:image,video,audio,document,sticker'],
            'caption' => ['nullable', new SafePlainText(maxBytes: 1024, allowWhitespace: true)],
            'voice_note' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * El MIME real se valida acá y no con la regla `mimes:`, que en Laravel se
     * apoya en la extensión declarada. Un `.jpg` con un SVG adentro pasaría esa
     * regla y fallaría esta.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');
                $type = (string) $this->input('type');

                if ($file === null || is_array($file) || ! $file->isValid()) {
                    return;
                }

                $allowed = (array) config("evolution.media.allowed_mimes.{$type}", []);
                $mime = (string) $file->getMimeType();

                if (! in_array($mime, $allowed, true)) {
                    $validator->errors()->add(
                        'file',
                        "El tipo de archivo no está permitido para «{$type}» ({$mime})."
                    );
                }
            },
        ];
    }

    /** Nombre original saneado. Metadato: nunca parte de la clave en S3. */
    public function safeFileName(): string
    {
        $file = $this->file('file');
        $name = is_array($file) || $file === null ? '' : (string) $file->getClientOriginalName();

        // basename() corta cualquier intento de path traversal en el nombre.
        return mb_strcut(SafePlainText::sanitize(basename($name)), 0, 200);
    }
}

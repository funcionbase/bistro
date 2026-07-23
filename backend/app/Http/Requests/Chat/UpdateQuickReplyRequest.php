<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición de una respuesta rápida. Se edita el texto (título/cuerpo); el alcance
 * (empresa vs sede) no se mueve acá — para cambiarlo se borra y se crea de nuevo,
 * que evita el caso raro de una respuesta que cambia de sede bajo los pies del
 * operador que la estaba usando.
 */
class UpdateQuickReplyRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'title' => 'plain_text_short',
        'body' => 'plain_text_long',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', new SafePlainText(maxBytes: 80, allowWhitespace: true)],
            'body' => ['required', new SafePlainText(maxBytes: 2000, allowWhitespace: true)],
        ];
    }
}

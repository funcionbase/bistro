<?php

namespace App\Http\Requests\Chat;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

class UpdateChatContactRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'name' => 'plain_text_short',
        'notes' => 'plain_text_long',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', new SafePlainText(maxBytes: 120)],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s]+$/'],
            // Dirección estructurada (misma que /clients). 'sometimes': solo se
            // toca lo que el editor manda; su ausencia no borra lo guardado.
            'address' => ['sometimes', 'nullable', new SafePlainText(maxBytes: 200, allowWhitespace: true)],
            'neighborhood' => ['sometimes', 'nullable', new SafePlainText(maxBytes: 120, allowWhitespace: true)],
            'municipality_dane_code' => ['sometimes', 'nullable', 'string', 'size:5', 'regex:/^[0-9]{5}$/'],
        ];
    }
}

<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Models\User;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: PUT /settings/profile (ProfileController). Requiere sesión web autenticada (Breeze).
 *
 * El email se ignora (ignore) para el usuario actual al validar unicidad.
 */
class ProfileUpdateRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'first_name' => 'plain_text_short',
        'last_name' => 'plain_text_short',
        'email' => 'identifier',
    ];

    /**
     * Get the validation rules that apply to the request.
     *
     * El nombre se captura como first_name + last_name por separado; `users.name`
     * es columna generada y no se valida ni escribe directo.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', new SafePlainText(maxBytes: 100)],
            'last_name' => ['required', new SafePlainText(maxBytes: 100)],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],

            'cedula' => ['nullable', 'string', 'regex:/^[0-9]{5,20}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'cedula.regex' => 'La cédula debe contener solo dígitos (5 a 20 caracteres).',
        ];
    }
}

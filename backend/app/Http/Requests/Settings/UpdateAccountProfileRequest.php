<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Models\User;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: PATCH /api/v1/account/profile (AccountController). Requiere JWT.
 *
 * El nombre se captura SIEMPRE como first_name + last_name por separado; la
 * columna `users.name` es generada y no se escribe. first/last se sanean
 * (strip_tags + NFC + colapso de whitespace) vía SanitizesInput y se validan
 * con SafePlainText (máx en bytes, sin control chars). El email ignora la
 * unicidad del propio usuario (resuelto del payload JWT).
 */
class UpdateAccountProfileRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'first_name' => 'plain_text_short',
        'last_name' => 'plain_text_short',
        'email' => 'identifier',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
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
                Rule::unique(User::class)->ignore($this->userId()),
            ],
            'cedula' => ['nullable', 'string', 'regex:/^[0-9]{5,20}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cedula.regex' => 'La cédula debe contener solo dígitos (5 a 20 caracteres).',
        ];
    }

    private function userId(): ?string
    {
        $payload = $this->attributes->get('jwt_payload');

        return is_array($payload) ? ($payload['sub'] ?? null) : null;
    }
}

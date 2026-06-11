<?php

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: POST /api/enrollment/user (UserEnrollmentController). Requiere JWT con enrollment_step='pending_enrollment'.
 *
 * first_name/last_name se sanean (strip_tags + NFC + colapso de whitespace) vía
 * SanitizesInput y se validan con SafePlainText (máx en bytes, sin control chars).
 * La cédula es solo dígitos. La unicidad NO se valida acá: si ya pertenece a
 * otra cuenta, el controller ofrece recuperarla por correo (cambio de correo)
 * en vez de un dead-end. accept_tos y accept_privacy deben ser 'true'/'1'.
 */
class UserEnrollmentRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'first_name' => 'plain_text_short',
        'last_name' => 'plain_text_short',
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
            'cedula' => ['required', 'string', 'regex:/^[0-9]{1,20}$/'],
            'accept_tos' => ['required', 'accepted'],
            'accept_privacy' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept_tos.accepted' => 'Debes aceptar los Términos y Condiciones.',
            'accept_privacy.accepted' => 'Debes aceptar la Política de Privacidad.',
            'cedula.regex' => 'La cédula debe contener solo dígitos (máx. 20).',
        ];
    }
}

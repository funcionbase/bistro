<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: POST /api/auth/select-company (AuthController::selectCompany). Requiere JWT válido (middleware ValidateJwt).
 *
 * Valida que 'nit' esté presente; la membresía activa y el status de la empresa se verifican en el controlador.
 */
class SelectCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'nit' => ['required', 'string'],
        ];
    }
}

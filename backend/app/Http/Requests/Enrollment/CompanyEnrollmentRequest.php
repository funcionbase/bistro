<?php

namespace App\Http\Requests\Enrollment;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Endpoint: POST /api/enrollment/company (CompanyEnrollmentController). Requiere JWT con user status='active'.
 *
 * El NIT debe ser único en companies. bank_id debe existir en la tabla banks.
 * account_type solo acepta 'corriente' o 'ahorros'. qr_code opcional: PNG/JPG/JPEG, máx 2MB.
 * accept_contract debe ser 'true'/'1'.
 *
 * `proof_document` es obligatorio (evidencia de propiedad de la
 * empresa). Aceptados PDF, Word (.doc, .docx) e imagen (JPG/PNG). El backend
 * valida MIME real con `mimetypes` (no extensión) y peso máximo 10 MB.
 */
class CompanyEnrollmentRequest extends FormRequest
{
    /**
     * Acceso dual: el registro por correo/contraseña exige verificar el correo
     * ANTES de registrar empresa. Vive en authorize() (corre antes de las
     * reglas) para que el no-verificado reciba el 403 con `code`, no errores
     * de campos. `ensureGoogleEmailVerified` backfillea cuentas Google legacy
     * que nunca persistieron email_verified_at (Google ya lo verificó).
     */
    public function authorize(): bool
    {
        $sub = $this->attributes->get('jwt_payload')['sub'] ?? null;
        $user = $sub !== null ? User::query()->find($sub) : null;

        if ($user === null) {
            return false;
        }

        $user->ensureGoogleEmailVerified();

        return $user->email_verified_at !== null;
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Verifica tu correo antes de registrar tu empresa. Revisa el enlace que te enviamos.',
            'code' => 'email_not_verified',
        ], 403));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // NIT: solo dígitos y guiones (ej. 900123456-7).
            'nit' => ['required', 'string', 'max:20', 'regex:/^[0-9-]+$/', 'unique:companies,nit'],
            'commercial_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['required', 'string', 'max:255'],
            'bank_id' => ['required', 'uuid', 'exists:banks,id'],
            'account_number' => ['required', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'account_type' => ['required', 'string', Rule::in(['corriente', 'ahorros'])],
            'qr_code' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
            // Llave Bre-B: alfanumérica con @ . - permitidos.
            'breb_key' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9@.-]+$/'],
            'accept_contract' => ['required', 'accepted'],
            // Multi-sede: el onboarding crea automáticamente la sede default
            // (slug='principal'). Estos campos opcionales permiten al cliente darle
            // un nombre/dirección distintos desde el primer momento.
            'main_branch_name' => ['nullable', 'string', 'max:120'],
            'main_branch_address' => ['nullable', 'string', 'max:255'],
            'main_branch_city' => ['nullable', 'string', 'max:120'],
            // Vertical de la primera sede. Si no llega, default a
            // 'restaurant' (compatibilidad histórica). El wizard frontend lo
            // expone como selector en el paso de "primera sede".
            'main_branch_business_type' => ['nullable', 'string', 'exists:business_types,slug'],
            // Evidencia de propiedad. `mimetypes` lee el contenido del
            // archivo, no la extensión — defensa contra rename malicioso.
            'proof_document' => [
                'required',
                'file',
                'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png',
                'max:10240',
            ],
            // Promo code opcional desde URL `?promo=...`. Se valida en
            // el service (validateBySlug) — acá solo aceptamos el slug.
            'promo_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]{2,50}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nit.unique' => 'Este NIT ya está registrado.',
            'nit.regex' => 'El NIT solo admite números y guiones.',
            'account_number.regex' => 'El número de cuenta solo admite dígitos.',
            'breb_key.regex' => 'La llave Bre-B solo admite letras, números y los signos @ . -',
            'bank_id.exists' => 'El banco seleccionado no es válido.',
            'account_type.in' => 'El tipo de cuenta debe ser corriente o ahorros.',
            'accept_contract.accepted' => 'Debes aceptar el Contrato antes de continuar.',
            'proof_document.required' => 'Debes adjuntar el documento de propiedad de la empresa.',
            'proof_document.file' => 'El documento adjunto no es válido.',
            'proof_document.mimetypes' => 'Formato no permitido. Acepta PDF, Word (.doc, .docx) o imagen (JPG/PNG).',
            'proof_document.max' => 'El documento supera el tamaño máximo de 10 MB.',
        ];
    }
}

<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación para actualizar colaboradores. Mismas reglas que store pero
 * todos los campos opcionales (PATCH-style merge).
 */
class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'primary_branch_id' => ['sometimes', 'uuid'],
            'position_id' => ['nullable', 'uuid'],
            'doc_type' => ['sometimes', Rule::in(['CC', 'CE', 'PA', 'PEP', 'TI'])],
            'doc_number' => ['sometimes', 'string', 'max:32'],
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email:rfc', 'max:180'],
            'phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'blood_type' => ['nullable', Rule::in(['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'])],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'eps' => ['nullable', 'string', 'max:120'],
            'arl' => ['nullable', 'string', 'max:120'],
            'pension_fund' => ['nullable', 'string', 'max:120'],
            'severance_fund' => ['nullable', 'string', 'max:120'],
            'bank' => ['nullable', 'string', 'max:120'],
            'account_type' => ['nullable', Rule::in(['ahorros', 'corriente'])],
            'account_number' => ['nullable', 'string', 'max:32'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'uniform_size' => ['nullable', 'string', 'max:20'],
            'contract_type' => ['nullable', Rule::in(['fijo', 'indefinido', 'OPS', 'aprendizaje'])],
            'base_salary' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'pay_type' => ['sometimes', Rule::in(['hora', 'diario', 'semanal', 'quincenal', 'mensual'])],
            'pay_rate' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'hire_date' => ['nullable', 'date'],
            'extra_branch_ids' => ['array'],
            'extra_branch_ids.*' => ['uuid'],
            'min_days_off_override' => ['nullable', 'integer', 'min:0', 'max:7'],
        ];
    }
}

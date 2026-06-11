<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación para crear colaboradores. La unicidad de (company_nit, doc_number)
 * y (company_nit, email) se asume a nivel de schema; aquí solo cubrimos
 * shape y formatos legibles para que el frontend muestre errores claros.
 */
class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'primary_branch_id' => ['required', 'uuid'],
            'position_id' => ['nullable', 'uuid'],
            'doc_type' => ['required', Rule::in(['CC', 'CE', 'PA', 'PEP', 'TI'])],
            'doc_number' => ['required', 'string', 'max:32'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:180'],
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
            'pay_type' => ['required', Rule::in(['hora', 'diario', 'semanal', 'quincenal', 'mensual'])],
            'pay_rate' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'hire_date' => ['nullable', 'date'],
            'extra_branch_ids' => ['array'],
            'extra_branch_ids.*' => ['uuid', 'different:primary_branch_id'],
            'min_days_off_override' => ['nullable', 'integer', 'min:0', 'max:7'],
        ];
    }
}

<?php

namespace App\Http\Requests\Employees;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación para crear colaboradores. La unicidad de (company_nit, doc_number)
 * y (company_nit, email) se asume a nivel de schema; aquí solo cubrimos
 * shape y formatos legibles para que el frontend muestre errores claros.
 */
class StoreEmployeeRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'doc_number' => 'plain_text_short',
        'first_name' => 'plain_text_short',
        'last_name' => 'plain_text_short',
        'address' => 'plain_text_long',
        'city' => 'plain_text_short',
        'eps' => 'plain_text_short',
        'arl' => 'plain_text_short',
        'pension_fund' => 'plain_text_short',
        'severance_fund' => 'plain_text_short',
        'bank' => 'plain_text_short',
        'account_number' => 'plain_text_short',
        'emergency_contact_name' => 'plain_text_short',
        'uniform_size' => 'plain_text_short',
    ];

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
            'doc_number' => ['required', new SafePlainText(maxBytes: 32, allowWhitespace: false)],
            'first_name' => ['required', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'last_name' => ['required', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'email' => ['required', 'email:rfc', 'max:180'],
            'phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'blood_type' => ['nullable', Rule::in(['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'])],
            'address' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
            'city' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'eps' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'arl' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'pension_fund' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'severance_fund' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'bank' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'account_type' => ['nullable', Rule::in(['ahorros', 'corriente'])],
            'account_number' => ['nullable', new SafePlainText(maxBytes: 32, allowWhitespace: false)],
            'emergency_contact_name' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'uniform_size' => ['nullable', new SafePlainText(maxBytes: 20, allowWhitespace: false)],
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

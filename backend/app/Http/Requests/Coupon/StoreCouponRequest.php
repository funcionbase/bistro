<?php

namespace App\Http\Requests\Coupon;

use App\Http\Requests\Concerns\SanitizesInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: POST /api/coupons (CouponController::store). Requiere coupons.create.
 *
 * El código se normaliza a mayúsculas en prepareForValidation. Longitud: config('coupons.code.min_length', 4)–max_length(20).
 * Solo caracteres A-Z, 0-9, guión y guión bajo.
 * Para type=percentage: valor máximo config('coupons.validation.max_percentage', 80)%.
 * Para type=fixed_amount: valor máximo config('coupons.validation.max_fixed_amount', 100000).
 * El código debe ser único por empresa (incluye soft-deleted).
 */
class StoreCouponRequest extends FormRequest
{
    use SanitizesInput;

    /**
     * El código se categoriza como `identifier`. La sanitización del trait
     * hace lower+trim, luego se promueve a mayúsculas en prepareForValidation
     * para preservar la convención existente del proyecto.
     *
     * @var array<string, string>
     */
    protected array $sanitize = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyNit = $this->attributes->get('active_company_nit');
        $minLen = config('coupons.code.min_length', 4);
        $maxLen = config('coupons.code.max_length', 20);

        return [
            'code' => [
                'required',
                'string',
                "min:{$minLen}",
                "max:{$maxLen}",
                'regex:/^[A-Z0-9\-_]+$/',
                Rule::unique('coupons')->where('company_nit', $companyNit)->whereNull('deleted_at'),
            ],
            'type' => ['required', 'string', Rule::in(['percentage', 'fixed_amount'])],
            'value' => ['required', 'numeric', 'gt:0'],
            'valid_from' => ['nullable', 'date', 'before_or_equal:valid_until'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'first_order_only' => ['nullable', 'boolean'],
            // Programación (happy hour). valid_days: array de ints 0-6.
            // valid_hours_from/to: ambos o ninguno. auto_apply: boolean.
            'valid_days' => ['nullable', 'array'],
            'valid_days.*' => ['integer', 'between:0,6'],
            'valid_hours_from' => ['nullable', 'required_with:valid_hours_to', 'date_format:H:i'],
            'valid_hours_to' => ['nullable', 'required_with:valid_hours_from', 'date_format:H:i'],
            'auto_apply' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->type === 'percentage') {
                $max = config('coupons.validation.max_percentage', 80);
                if ($this->value > $max) {
                    $v->errors()->add('value', "El porcentaje no puede superar {$max}%");
                }
            } elseif ($this->type === 'fixed_amount') {
                $max = config('coupons.validation.max_fixed_amount', 100000);
                if ($this->value > $max) {
                    $formatted = number_format($max, 0, ',', '.');
                    $v->errors()->add('value', "El monto no puede superar \${$formatted}");
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeMappedFields();

        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }
}

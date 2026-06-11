<?php

namespace App\Http\Requests\Hours;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: POST /api/business-hours/exceptions (BusinessHoursController::storeException). Requiere hours.update.
 *
 * La exception_date debe ser única por empresa (no puede haber dos excepciones el mismo día).
 * Si is_open=false (cierre especial), open_time y close_time se ignoran.
 */
class StoreBusinessHourExceptionRequest extends FormRequest
{
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

        return [
            'exception_date' => [
                'required',
                'date',
                'date_format:Y-m-d',
                Rule::unique('business_hour_exceptions')->where(fn ($query) => $query->where('company_nit', $companyNit)),
            ],
            'reason' => ['required', 'string', 'max:255'],
            'is_open' => ['required', 'boolean'],
            'open_time' => ['nullable', 'date_format:H:i', 'required_if:is_open,true'],
            'close_time' => ['nullable', 'date_format:H:i', 'required_if:is_open,true', 'after:open_time'],
        ];
    }
}

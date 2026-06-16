<?php

namespace App\Http\Requests\Hours;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: PUT /api/business-hours/exceptions/{id} (BusinessHoursController::updateException). Requiere hours.update.
 *
 * El controlador rechaza modificaciones a excepciones de fechas pasadas (retorna 422).
 * La exception_date debe ser única por empresa excluyendo el registro actual (ignore).
 */
class UpdateBusinessHourExceptionRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'reason' => 'plain_text_long',
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
        $companyNit = $this->attributes->get('active_company_nit');
        $exceptionId = $this->route('id');

        return [
            'exception_date' => [
                'required',
                'date',
                'date_format:Y-m-d',
                Rule::unique('business_hour_exceptions')
                    ->where(fn ($query) => $query->where('company_nit', $companyNit))
                    ->ignore($exceptionId),
            ],
            'reason' => ['required', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
            'is_open' => ['required', 'boolean'],
            'open_time' => ['nullable', 'date_format:H:i', 'required_if:is_open,true'],
            'close_time' => ['nullable', 'date_format:H:i', 'required_if:is_open,true', 'after:open_time'],
        ];
    }
}

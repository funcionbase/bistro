<?php

namespace App\Http\Requests\Metrics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Endpoint: GET /api/v1/metrics/foodcost/summary (FoodCostController). Requiere reports.read.
 *
 * Períodos: today, week, month, custom. limit opcional (1..200) para la tabla por ítem.
 */
class GetFoodCostSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', Rule::in(['today', 'week', 'month', 'custom'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('period') === 'custom') {
                    if (! $this->input('date_from')) {
                        $validator->errors()->add('date_from', 'date_from es requerido si period=custom.');
                    }
                    if (! $this->input('date_to')) {
                        $validator->errors()->add('date_to', 'date_to es requerido si period=custom.');
                    }
                }
            },
        ];
    }
}

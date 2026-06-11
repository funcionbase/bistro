<?php

namespace App\Http\Requests\Metrics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: GET /api/metrics/top-dishes (MetricsController). Requiere reports.read.
 *
 * Períodos: today, this_week, this_month. limit: top N platos, entre 1 y 50.
 */
class GetTopDishesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', Rule::in(['today', 'this_week', 'this_month'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}

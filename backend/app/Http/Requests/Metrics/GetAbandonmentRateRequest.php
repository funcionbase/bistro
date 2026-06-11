<?php

namespace App\Http\Requests\Metrics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: GET /api/metrics/abandonment-rate (MetricsController). Requiere reports.read.
 *
 * Períodos: today, this_week, this_month.
 */
class GetAbandonmentRateRequest extends FormRequest
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
        ];
    }
}

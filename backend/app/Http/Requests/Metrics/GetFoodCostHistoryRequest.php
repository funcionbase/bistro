<?php

namespace App\Http\Requests\Metrics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Endpoint: GET /api/v1/metrics/foodcost/items/{menuItemId}/history (FoodCostController). Requiere reports.read.
 *
 * Lee snapshots diarios desde menu_item_cost_history para sparkline / línea de evolución.
 */
class GetFoodCostHistoryRequest extends FormRequest
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

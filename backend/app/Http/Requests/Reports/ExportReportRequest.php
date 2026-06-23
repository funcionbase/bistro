<?php

namespace App\Http\Requests\Reports;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Endpoint: POST /api/reports/export (OrderReportController::export). Requiere reports.read.
 *
 * Igual que OrderReportRequest pero sin paginación; rango máximo: config('reports.max_date_range_days', 90) días.
 * El resultado es un token de descarga asíncrono (job en cola GenerateReportPdf).
 */
class ExportReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'period' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'date_from' => ['required_if:period,custom', 'nullable', 'date', 'date_format:Y-m-d'],
            'date_to' => ['required_if:period,custom', 'nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['sometimes', 'nullable', Rule::in(array_merge(['all'], config('orders.all')))],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'date_from' => 'fecha desde',
            'date_to' => 'fecha hasta',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->validated()['period'] !== 'custom') {
                    return;
                }

                $dateFrom = $this->input('date_from');
                $dateTo = $this->input('date_to');

                if (! $dateFrom || ! $dateTo) {
                    return;
                }

                $maxDays = (int) config('reports.max_date_range_days', 90);
                $diff = Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo));

                if ($diff > $maxDays) {
                    $validator->errors()->add('date_to', "El rango máximo permitido es de {$maxDays} días.");
                }
            },
        ];
    }
}

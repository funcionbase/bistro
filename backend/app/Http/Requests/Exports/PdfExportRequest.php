<?php

namespace App\Http\Requests\Exports;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Endpoint: POST /api/exports/pdf (PdfExportController). Requiere el permiso según el dominio exportado.
 *
 * filters.date_from/date_to: rango máximo config('reports.max_date_range_days', 90) días.
 * columns: lista de columnas a incluir en el PDF; máx 20 columnas de hasta 50 caracteres.
 */
class PdfExportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filters' => ['nullable', 'array'],
            'filters.date_from' => ['nullable', 'date', 'date_format:Y-m-d'],
            'filters.date_to' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:filters.date_from'],
            'filters.status' => ['nullable'],
            'filters.search' => ['nullable', 'string', 'max:100'],
            'columns' => ['nullable', 'array', 'max:20'],
            'columns.*' => ['string', 'max:50'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $filters = $this->validated()['filters'] ?? [];
                $dateFrom = $filters['date_from'] ?? null;
                $dateTo = $filters['date_to'] ?? null;

                if (! $dateFrom || ! $dateTo) {
                    return;
                }

                $maxDays = (int) config('reports.max_date_range_days', 90);
                $diff = Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo));

                if ($diff > $maxDays) {
                    $validator->errors()->add('filters.date_to', "El rango máximo permitido es de {$maxDays} días.");
                }
            },
        ];
    }
}

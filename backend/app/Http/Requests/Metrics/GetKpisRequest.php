<?php

namespace App\Http\Requests\Metrics;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: GET /api/metrics/kpis (MetricsController). Requiere reports.read.
 *
 * Sin parámetros requeridos; el período por defecto es 'today'.
 */
class GetKpisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}

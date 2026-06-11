<?php

namespace App\Http\Requests\Metrics;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: GET /api/metrics/active-orders (MetricsController). Requiere reports.read.
 *
 * Sin parámetros; retorna pedidos en estado activo de la empresa en tiempo real.
 */
class GetActiveOrdersRequest extends FormRequest
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

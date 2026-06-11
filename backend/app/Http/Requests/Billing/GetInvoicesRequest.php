<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: GET /api/billing/invoices (BillingController). Requiere rol owner/admin de la empresa activa.
 *
 * year: mínimo 2024, máximo año actual. status: pending, paid, overdue, voided.
 * per_page: entre 5 y 50.
 */
class GetInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
            'status' => ['nullable', 'string', 'in:pending,paid,overdue,voided'],
            'year' => ['nullable', 'integer', 'min:2024', 'max:'.now()->year],
        ];
    }
}

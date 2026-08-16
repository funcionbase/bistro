<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/company/kds/stations — crear estación KDS.
 *
 * Reglas:
 *  - `name`: requerido, plain text 1..64 bytes.
 *  - `slug`: requerido, snake/kebab 1..64 chars, único por (company, branch).
 *  - `color`: hex `#RRGGBB` (mayúsculas o minúsculas).
 *  - SLA: enteros 1..120; `warn < alert` validado en `withValidator`.
 *  - `is_default`: si true, el controller debe desactivar el flag en las otras
 *    estaciones de la sede dentro de una transacción.
 */
class StoreKdsStationRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'name' => 'plain_text_short',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyNit = $this->attributes->get('active_company_nit');
        $branchId = $this->attributes->get('active_branch_id');

        return [
            'name' => ['required', new SafePlainText(maxBytes: 64)],
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('kds_stations', 'slug')->where(function ($q) use ($companyNit, $branchId) {
                    $q->where('company_nit', $companyNit)
                        ->where('branch_id', $branchId);
                }),
            ],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sla_warn_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'sla_alert_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $warn = (int) $this->input('sla_warn_minutes', 0);
            $alert = (int) $this->input('sla_alert_minutes', 0);
            if ($warn > 0 && $alert > 0 && $warn >= $alert) {
                $v->errors()->add('sla_warn_minutes', 'El umbral de aviso debe ser menor que el de alerta.');
            }
        });
    }
}

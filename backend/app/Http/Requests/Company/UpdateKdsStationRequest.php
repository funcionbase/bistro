<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/company/kds/stations/{id} — actualiza estación.
 *
 * `slug` no se permite cambiar (mantiene estable las referencias en JSON
 * del menú y los device-tokens). Si se necesita renombrar usar `name`.
 */
class UpdateKdsStationRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'required', new SafePlainText(maxBytes: 64)],
            'color' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sla_warn_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:120'],
            'sla_alert_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $warn = $this->input('sla_warn_minutes');
            $alert = $this->input('sla_alert_minutes');
            if (is_numeric($warn) && is_numeric($alert) && (int) $warn >= (int) $alert) {
                $v->errors()->add('sla_warn_minutes', 'El umbral de aviso debe ser menor que el de alerta.');
            }
        });
    }
}

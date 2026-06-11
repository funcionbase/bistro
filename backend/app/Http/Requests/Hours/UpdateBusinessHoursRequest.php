<?php

namespace App\Http\Requests\Hours;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: PUT /api/business-hours (BusinessHoursController::update). Requiere hours.update.
 *
 * Se deben enviar exactamente 7 entradas (una por día). day_of_week: 0=domingo, 6=sábado (Carbon).
 * Si is_enabled=false, open_time y close_time se ignoran y se guardan como null.
 */
class UpdateBusinessHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El frontend mantiene los valores de open/close_time aunque el día se
     * deshabilite (mejor UX al re-habilitar). Para que la regla `after` sobre
     * close_time NO se ejecute en días apagados (por ejemplo `00:00` → `00:00`
     * dispararía 422 y bloquearía el lote completo de los 7 días),
     * normalizamos a null antes de validar.
     */
    protected function prepareForValidation(): void
    {
        $hours = $this->input('hours');
        if (! is_array($hours)) {
            return;
        }

        foreach ($hours as $i => $hour) {
            if (! is_array($hour)) {
                continue;
            }
            if (empty($hour['is_enabled'])) {
                $hours[$i]['open_time'] = null;
                $hours[$i]['close_time'] = null;
            }
        }

        $this->merge(['hours' => $hours]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hours' => ['required', 'array', 'min:7', 'max:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'hours.*.is_enabled' => ['required', 'boolean'],
            'hours.*.open_time' => ['nullable', 'date_format:H:i', 'required_if:hours.*.is_enabled,true'],
            'hours.*.close_time' => ['nullable', 'date_format:H:i', 'required_if:hours.*.is_enabled,true', 'after:hours.*.open_time'],
        ];
    }
}

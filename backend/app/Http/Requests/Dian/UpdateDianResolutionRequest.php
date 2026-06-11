<?php

declare(strict_types=1);

namespace App\Http\Requests\Dian;

use App\Http\Requests\Concerns\SanitizesInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDianResolutionRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        // Solo permitimos cambiar la vigencia y desactivar. Datos críticos
        // (prefix, range, resolution_number, technical_key) son inmutables —
        // si cambian, se crea una resolución nueva y se marca la anterior
        // como is_active=false (paper trail DIAN).
        return [
            'valid_until' => ['sometimes', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

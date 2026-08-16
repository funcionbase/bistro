<?php

namespace App\Http\Requests\Menu;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: POST /api/menus/{id}/categories (MenuController). Requiere menu.update.
 *
 * order: posición de la categoría en el menú (entero ≥ 0); si se omite, se añade al final.
 * kds_station_id: estación de cocina a la que se enrutan los items
 * de la categoría en el KDS. Debe ser una estación activa de la misma
 * sede del menú. Si se omite o se manda null, los items caen en la
 * estación `is_default=true` de la sede (fallback).
 */
class StoreCategoryRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'name' => 'plain_text_short',
        'description' => 'plain_text_long',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', new SafePlainText(maxBytes: 128)],
            'description' => ['nullable', new SafePlainText(maxBytes: 512, allowWhitespace: true)],
            'order' => ['nullable', 'integer', 'min:0'],
            'kds_station_id' => [
                'nullable',
                'uuid',
                Rule::exists('kds_stations', 'id')->where(function ($q) {
                    $q->where('company_nit', $this->attributes->get('active_company_nit'))
                        ->where('branch_id', $this->attributes->get('active_branch_id'))
                        ->whereNull('archived_at');
                }),
            ],
        ];
    }
}

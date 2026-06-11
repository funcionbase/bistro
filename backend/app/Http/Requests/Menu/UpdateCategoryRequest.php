<?php

namespace App\Http\Requests\Menu;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: PUT /api/menus/{id}/categories/{catId} (MenuController). Requiere menu.update.
 *
 * kds_station_id (#115): mismo contrato que en StoreCategoryRequest. Si se
 * manda null se desasocia la categoría (cae al fallback `is_default`); si
 * se omite del payload, el valor previo se preserva en el controller.
 */
class UpdateCategoryRequest extends FormRequest
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
                'sometimes',
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

<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'name' => 'plain_text_short',
        'address' => 'plain_text_long',
        'city' => 'plain_text_short',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyNit = (string) $this->attributes->get('active_company_nit');
        $branchId = (string) $this->route('branch');

        return [
            'name' => ['sometimes', 'required', new SafePlainText(maxBytes: 120)],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:60',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('branches', 'slug')
                    ->where(fn ($q) => $q->where('company_nit', $companyNit))
                    ->ignore($branchId, 'id'),
            ],
            'address' => ['sometimes', 'nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
            'city' => ['sometimes', 'nullable', new SafePlainText(maxBytes: 120)],
            'municipality_dane_code' => ['sometimes', 'nullable', 'string', 'size:5', 'regex:/^[0-9]{5}$/'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'El slug solo permite minúsculas, números y guiones.',
            'slug.unique' => 'Ya existe una sede con ese slug en esta empresa.',
        ];
    }
}

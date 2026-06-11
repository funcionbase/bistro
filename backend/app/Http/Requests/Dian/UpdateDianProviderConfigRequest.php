<?php

declare(strict_types=1);

namespace App\Http\Requests\Dian;

use App\Http\Requests\Concerns\SanitizesInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDianProviderConfigRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'provider_slug' => 'identifier',
        'software_id' => 'identifier',
        'test_set_id' => 'identifier',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'provider_slug' => ['required', 'string', 'in:mock,factura1,siigo'],
            'api_base_url' => ['nullable', 'url', 'max:500'],
            'api_token' => ['nullable', 'string', 'max:1000'],
            'software_id' => ['nullable', 'string', 'max:100'],
            'software_pin' => ['nullable', 'string', 'max:200'],
            'test_set_id' => ['nullable', 'string', 'max:100'],
            'environment' => ['required', 'string', 'in:habilitacion,produccion'],
            'webhook_secret' => ['nullable', 'string', 'max:256'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Dian;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

class StoreDianResolutionRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'prefix' => 'identifier',
        'resolution_number' => 'identifier',
        'technical_key' => 'identifier',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        $documentTypes = (array) config('dian.document_types_allowed', []);

        return [
            'document_type' => ['required', 'string', 'in:'.implode(',', $documentTypes)],
            'prefix' => ['required', new SafePlainText(maxBytes: 10, allowWhitespace: false)],
            'range_from' => ['required', 'integer', 'min:1'],
            'range_to' => ['required', 'integer', 'gt:range_from'],
            'resolution_number' => ['required', new SafePlainText(maxBytes: 50, allowWhitespace: false)],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after:valid_from'],
            'technical_key' => ['required', new SafePlainText(maxBytes: 200, allowWhitespace: false)],
            'environment' => ['required', 'string', 'in:habilitacion,produccion'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

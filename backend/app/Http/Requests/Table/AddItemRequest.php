<?php

declare(strict_types=1);

namespace App\Http\Requests\Table;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

class AddItemRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'notes' => 'plain_text_long',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'menu_item_id' => ['required', 'string', 'max:64'],
            'quantity' => ['required', 'integer', 'between:1,99'],
            'notes' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ];
    }
}

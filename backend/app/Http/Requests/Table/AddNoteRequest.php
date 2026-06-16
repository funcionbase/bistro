<?php

declare(strict_types=1);

namespace App\Http\Requests\Table;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

class AddNoteRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'body' => 'plain_text_long',
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
            'scope' => ['required', 'string', 'in:group,kitchen_alert'],
            'body' => ['required', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ];
    }
}

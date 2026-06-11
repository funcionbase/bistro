<?php

declare(strict_types=1);

namespace App\Http\Requests\Table;

use Illuminate\Foundation\Http\FormRequest;

class AddItemRequest extends FormRequest
{
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
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
